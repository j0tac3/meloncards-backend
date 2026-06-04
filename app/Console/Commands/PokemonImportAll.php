<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CardTemplate;
use App\Models\Game;
use App\Models\CardSet;
use App\Models\CardPrice;

class PokemonImportAll extends Command
{
    protected $signature   = 'pokemon:import-all
                                {--set= : Importar solo una expansión concreta (ej: base1)}
                                {--skip-prices : Omitir la importación de precios}';
    protected $description = 'Descarga masiva de todas las expansiones y cartas de Pokémon TCG';

    private const PAGE_SIZE  = 250;
    private const BATCH_SIZE = 200;
    private const DELAY_MS   = 250_000; // 250ms entre peticiones

    public function handle(): int
    {
        $game = Game::where('slug', 'pokemon')->first();
        if (!$game) {
            $this->error('❌ El juego Pokémon no existe en la base de datos.');
            return self::FAILURE;
        }

        $apiKey     = config('services.pokemontcg.key', env('POKEMONTCG_API_KEY'));
        $onlySet    = $this->option('set');
        $skipPrices = $this->option('skip-prices');

        // ── 1. Obtener lista de sets ──────────────────────────────────────────
        $this->info('📦 Solicitando lista de expansiones...');
        $sets = $this->fetchSets($apiKey);

        if (empty($sets)) {
            $this->error('❌ No se pudieron obtener las expansiones.');
            return self::FAILURE;
        }

        if ($onlySet) {
            $sets = array_filter($sets, fn($s) => $s['id'] === $onlySet);
            if (empty($sets)) {
                $this->error("❌ Set '{$onlySet}' no encontrado.");
                return self::FAILURE;
            }
        }

        $this->info('✅ ' . count($sets) . ' expansiones encontradas.');

        $totalCards  = 0;
        $totalPrices = 0;

        // ── 2. Procesar cada set ──────────────────────────────────────────────
        foreach ($sets as $set) {
            $this->newLine();
            $this->warn("📦 [{$set['id']}] {$set['name']}");

            // Crear/actualizar el set en BD
            $dbSet = CardSet::updateOrCreate(
                ['game_id' => $game->id, 'code' => $set['id']],
                [
                    'name'        => $set['name'],
                    'total_cards' => $set['printedTotal'] ?? $set['total'] ?? null,
                ]
            );

            // Descargar cartas paginando
            [$cards, $pricesCount] = $this->importSetCards(
                $set['id'], $dbSet->id, $game, $apiKey, $skipPrices
            );

            $totalCards  += $cards;
            $totalPrices += $pricesCount;

            $this->line("   ✅ {$cards} cartas | {$pricesCount} precios");
        }

        $this->newLine();
        $this->info("🎉 Completado. Cartas: {$totalCards} | Precios: {$totalPrices}");
        return self::SUCCESS;
    }

    // ─── Helpers privados ──────────────────────────────────────────────────────

    private function fetchSets(string $apiKey): array
    {
        try {
            $response = Http::timeout(60)
                ->retry(3, 2000)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->get('https://api.pokemontcg.io/v2/sets');

            return $response->successful() ? $response->json('data', []) : [];
        } catch (\Throwable $e) {
            Log::error('pokemon:import-all fetchSets failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Descarga todas las cartas de un set y las inserta en lote.
     * Devuelve [cards_importadas, precios_importados].
     */
    private function importSetCards(
        string $setId,
        int    $dbSetId,
        $game,
        string $apiKey,
        bool   $skipPrices
    ): array {
        $page         = 1;
        $cardsBatch   = [];
        $pricesBatch  = [];
        $totalCards   = 0;
        $totalPrices  = 0;

        while (true) {
            usleep(self::DELAY_MS);

            $cards = $this->fetchCardsPage($setId, $page, $apiKey);

            if ($cards === null) {
                $this->warn("   ⚠️  Fallo en página {$page}. Saltando set.");
                break;
            }

            if (empty($cards)) break; // Fin de la paginación

            foreach ($cards as $card) {
                $uniqueId = $game->slug . '-' . $card['id'];

                $cardsBatch[] = [
                    'api_id'      => $card['id'],
                    'card_set_id' => $dbSetId,
                    'unique_id'   => $uniqueId,
                    'name'        => $card['name'],
                    'card_number' => $card['number'] ?? null,
                    'rarity'      => $card['rarity'] ?? null,
                    'image_url'   => $card['images']['large'] ?? $card['images']['small'] ?? null,
                    'attributes'  => json_encode([
                        'hp'        => $card['hp'] ?? null,
                        'supertype' => $card['supertype'] ?? null,
                        'types'     => $card['types'] ?? null,
                    ]),
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ];

                // Precio de Cardmarket (averageSellPrice o trendPrice)
                if (!$skipPrices) {
                    $marketPrice = $card['cardmarket']['prices']['averageSellPrice']
                        ?? $card['cardmarket']['prices']['trendPrice']
                        ?? null;

                    if ($marketPrice !== null) {
                        $pricesBatch[$card['id']] = [
                            'api_id'   => $card['id'], // clave temporal para el join posterior
                            'price'    => $marketPrice,
                            'currency' => 'EUR',
                            'provider' => 'cardmarket',
                            'updated_at' => now(),
                            'created_at' => now(),
                        ];
                    }
                }
            }

            // Flush en lotes para no acumular demasiado en memoria
            if (count($cardsBatch) >= self::BATCH_SIZE) {
                $totalCards  += $this->flushCards($cardsBatch);
                $totalPrices += $this->flushPrices($pricesBatch);
                $cardsBatch  = [];
                $pricesBatch = [];
            }

            if (count($cards) < self::PAGE_SIZE) break; // Última página
            $page++;
        }

        // Lote residual
        if (!empty($cardsBatch)) {
            $totalCards  += $this->flushCards($cardsBatch);
            $totalPrices += $this->flushPrices($pricesBatch);
        }

        return [$totalCards, $totalPrices];
    }

    private function fetchCardsPage(string $setId, int $page, string $apiKey): ?array
    {
        try {
            $response = Http::timeout(60)
                ->retry(3, 2000)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->get('https://api.pokemontcg.io/v2/cards', [
                    'q'        => "set.id:{$setId}",
                    'pageSize' => self::PAGE_SIZE,
                    'page'     => $page,
                ]);

            return $response->successful() ? $response->json('data', []) : null;
        } catch (\Throwable $e) {
            Log::warning("pokemon:import-all page {$page} failed for {$setId}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function flushCards(array $batch): int
    {
        if (empty($batch)) return 0;

        DB::beginTransaction();
        try {
            CardTemplate::upsert(
                $batch,
                ['api_id'],
                ['card_set_id', 'unique_id', 'name', 'card_number',
                 'rarity', 'image_url', 'attributes', 'updated_at']
            );
            DB::commit();
            return count($batch);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('pokemon:import-all flushCards failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function flushPrices(array $pricesBatch): int
    {
        if (empty($pricesBatch)) return 0;

        // Resolver api_id → card_template_id en una sola query
        $apiIds    = array_column($pricesBatch, 'api_id');
        $idMap     = CardTemplate::whereIn('api_id', $apiIds)->pluck('id', 'api_id');

        $toInsert = [];
        foreach ($pricesBatch as $item) {
            if (!isset($idMap[$item['api_id']])) continue;
            $toInsert[] = [
                'card_template_id' => $idMap[$item['api_id']],
                'price'            => $item['price'],
                'currency'         => $item['currency'],
                'provider'         => $item['provider'],
                'updated_at'       => $item['updated_at'],
                'created_at'       => $item['created_at'],
            ];
        }

        if (empty($toInsert)) return 0;

        DB::beginTransaction();
        try {
            CardPrice::upsert(
                $toInsert,
                ['card_template_id', 'currency', 'provider'],
                ['price', 'updated_at']
            );
            DB::commit();
            return count($toInsert);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('pokemon:import-all flushPrices failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
