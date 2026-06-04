<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Game;
use App\Models\CardSet;
use App\Models\CardTemplate;

class OpImportCards extends Command
{
    protected $signature   = 'op:import-cards';
    protected $description = 'OP: Lee los JSON locales de cartas y los fusiona en la Base de Datos';

    // ── Tamaño del lote para upserts masivos ──────────────────────────────────
    private const BATCH_SIZE = 200;

    public function handle(): int
    {
        $this->info('📦 Escaneando JSONs de idiomas disponibles...');

        // ── 1. Cargar todos los JSON disponibles ──────────────────────────────
        [$regionData, $allCardIds] = $this->loadRegionJsons();

        if (empty($regionData)) {
            $this->error('❌ No hay archivos JSON. Ejecuta op:scrape-cards primero.');
            return self::FAILURE;
        }

        // ── 2. Validar juego ──────────────────────────────────────────────────
        $game = Game::where('slug', 'one-piece')->first();
        if (!$game) {
            $this->error('❌ El juego One Piece no existe. Ejecuta: php artisan op:system-setup');
            return self::FAILURE;
        }

        $this->info("🗃️  Total de cartas únicas a fusionar: " . count($allCardIds));

        // ── 3. Pre-cargar sets existentes en memoria (evita N queries) ────────
        $existingSets = CardSet::where('game_id', $game->id)
            ->get()
            ->keyBy(fn($s) => $s->code . '|' . $s->region);

        // ── 4. Procesar en lotes dentro de una transacción ────────────────────
        $bar = $this->output->createProgressBar(count($allCardIds));
        $bar->start();

        $upsertBatch = [];
        $errors      = 0;

        DB::beginTransaction();
        try {
            foreach ($allCardIds as $uniqueId) {
                $card = $this->buildCardData($uniqueId, $regionData, $game, $existingSets);

                if ($card === null) {
                    $errors++;
                    $bar->advance();
                    continue;
                }

                $upsertBatch[] = $card;

                // Vaciar lote cuando alcanza el tamaño máximo
                if (count($upsertBatch) >= self::BATCH_SIZE) {
                    $this->flushBatch($upsertBatch);
                    $upsertBatch = [];
                }

                $bar->advance();
            }

            // Vaciar el último lote parcial
            if (!empty($upsertBatch)) {
                $this->flushBatch($upsertBatch);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('❌ Error crítico durante el guardado. Rollback ejecutado.');
            $this->error($e->getMessage());
            Log::error('op:import-cards failed', ['exception' => $e]);
            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        if ($errors > 0) {
            $this->warn("⚠️  {$errors} cartas no pudieron procesarse (revisa los logs).");
        }

        // ── 5. Sincronizar contadores de sets ─────────────────────────────────
        $this->syncSetCounters($game->id);

        $this->info('🎉 ¡BD Reconstruida: Juego, Regiones y Cartas sincronizadas!');
        return self::SUCCESS;
    }

    // ─── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Lee todos los JSON de regiones del disco y devuelve
     * [regionData, allCardIds_únicos].
     */
    private function loadRegionJsons(): array
    {
        $regionData = [];
        $allCardIds = [];

        foreach (Storage::disk('local')->files() as $file) {
            if (!preg_match('/^one_piece_(.+)\.json$/', $file, $m)) continue;

            $region = $m[1];
            $data   = json_decode(Storage::disk('local')->get($file), true) ?? [];

            $regionData[$region] = $data;
            // array_keys es O(n), el merge al final es O(n) total → corrección del O(n²) original
            foreach (array_keys($data) as $id) {
                $allCardIds[$id] = true; // usar como set hash para unicidad
            }

            $this->line("   ✅ JSON [{$region}] cargado: " . count($data) . " cartas.");
        }

        return [$regionData, array_keys($allCardIds)];
    }

    /**
     * Construye el array de datos de una carta fusionando todos los idiomas.
     * Devuelve null si no hay fuente principal.
     */
    private function buildCardData(
        string $uniqueId,
        array  $regionData,
        $game,
        array  &$existingSets
    ): ?array {
        // Fuente principal: en > asia-en > lo que haya
        $mainSource = $regionData['en'][$uniqueId]
            ?? $regionData['asia-en'][$uniqueId]
            ?? collect($regionData)->first(fn($cards) => isset($cards[$uniqueId]))[$uniqueId]
            ?? null;

        if ($mainSource === null) return null;

        $cardNumber    = $mainSource['id'] ?? explode('_', $uniqueId)[0];
        $fallbackName  = $mainSource['name'] ?? 'Unknown';
        $fallbackImage = $mainSource['image_url'] ?? '';
        $fallbackSet   = $mainSource['set_name'] ?? 'Expansión ' . explode('-', $cardNumber)[0];

        preg_match('/(?:\[|【)(.*?)(?:\]|】)/u', $fallbackSet, $m);
        $realSetCode = $m[1] ?? explode('-', $cardNumber)[0] ?? 'PROMO';

        // ── Resolver/crear el set (con caché en memoria) ──────────────────────
        $setId = $this->resolveSetId($realSetCode, $fallbackSet, $game->id, $existingSets);

        // ── Atributos base y localizados ──────────────────────────────────────
        $localizedAttributes = ['name' => [], 'effect' => [], 'image_url' => [],
                                 'category' => [], 'feature' => [], 'rarity' => []];
        $baseAttributes = [];

        foreach ($regionData as $region => $cards) {
            if (!isset($cards[$uniqueId])) continue;
            $c = $cards[$uniqueId];

            foreach (array_keys($localizedAttributes) as $attr) {
                if (isset($c[$attr])) $localizedAttributes[$attr][$region] = $c[$attr];
            }

            if (empty($baseAttributes)) {
                $baseAttributes = array_filter([
                    'cost'      => $c['cost']      ?? null,
                    'power'     => $c['power']      ?? null,
                    'life'      => $c['life']       ?? null,
                    'color'     => $c['color']      ?? null,
                    'attribute' => $c['attribute']  ?? null,
                    'counter'   => $c['counter']    ?? null,
                ], fn($v) => $v !== null && $v !== '');
            }
        }

        return [
            'unique_id'   => $uniqueId,
            'card_set_id' => $setId,
            'card_number' => $cardNumber,
            'name'        => $fallbackName,
            'image_url'   => $fallbackImage,
            'attributes'  => json_encode(array_merge($baseAttributes, $localizedAttributes)),
            'updated_at'  => now(),
            'created_at'  => now(),
        ];
    }

    /**
     * Resuelve el ID de un set. Usa caché en memoria para evitar queries repetidas.
     */
    private function resolveSetId(
        string $code,
        string $fallbackSetName,
        int    $gameId,
        array  &$existingSets
    ): int {
        // Buscar en caché: primero en inglés, luego cualquier región
        $keyEn  = $code . '|en';
        $keyAny = collect($existingSets)->keys()
            ->first(fn($k) => str_starts_with($k, $code . '|'));

        if (isset($existingSets[$keyEn]))  return $existingSets[$keyEn]->id;
        if ($keyAny && isset($existingSets[$keyAny])) return $existingSets[$keyAny]->id;

        // No existe → crear
        $cleanName = trim(preg_replace('/(?:\[|【).*?(?:\]|】)/u', '', $fallbackSetName));
        $newSet = CardSet::create([
            'game_id' => $gameId,
            'name'    => $cleanName,
            'code'    => $code,
            'region'  => 'en',
        ]);

        $existingSets[$keyEn] = $newSet;
        return $newSet->id;
    }

    /**
     * Ejecuta un upsert en lote. Mucho más rápido que N×updateOrCreate.
     */
    private function flushBatch(array $batch): void
    {
        CardTemplate::upsert(
            $batch,
            ['unique_id'],                                          // columnas de búsqueda
            ['card_set_id', 'card_number', 'name', 'image_url',   // columnas a actualizar
             'attributes', 'updated_at']
        );
    }

    /**
     * Sincroniza el contador total de cartas en todos los sets.
     */
    private function syncSetCounters(int $gameId): void
    {
        $this->info('🧮 Sincronizando contadores de sets...');

        // Una sola query con GROUP BY en lugar de N+1 queries
        $counts = CardTemplate::join('card_sets', 'card_sets.id', '=', 'card_templates.card_set_id')
            ->where('card_sets.game_id', $gameId)
            ->select('card_sets.code', DB::raw('COUNT(*) as total'))
            ->groupBy('card_sets.code')
            ->pluck('total', 'code');

        foreach ($counts as $code => $total) {
            CardSet::where('code', $code)->update(['total_cards' => $total]);
        }
    }
}
