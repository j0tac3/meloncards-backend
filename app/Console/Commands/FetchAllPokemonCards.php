<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\CardTemplate;
use App\Models\Game;
use App\Models\CardSet;
use App\Models\CardPrice;

class FetchAllPokemonCards extends Command
{
    protected $signature = 'pokemon:fetch-all';
    protected $description = 'Descarga masiva de TODAS las expansiones y cartas de Pokémon';

    public function handle()
    {
        $game = Game::where('slug', 'pokemon')->first();

        if (!$game) {
            $this->error('Error: El juego Pokémon no existe en la base de datos.');
            return;
        }

        $apiKey = env('POKEMONTCG_API_KEY');
        
        $this->info("Paso 1: Solicitando la lista completa de expansiones...");

        try {
            $setsResponse = Http::timeout(60)->retry(3, 1000)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->get("https://api.pokemontcg.io/v2/sets");

            if (!$setsResponse->successful()) {
                $this->error('Error al conectar con la API para obtener las expansiones.');
                return;
            }
        } catch (\Exception $e) {
            $this->error('Error crítico conectando con la API: ' . $e->getMessage());
            return;
        }

        $sets = $setsResponse->json()['data'];
        $this->info("¡Encontradas " . count($sets) . " expansiones! Iniciando descarga masiva...");
        $this->newLine();

        $totalCardsImported = 0;

        foreach ($sets as $set) {
            $setId = $set['id'];
            $setName = $set['name'];
            $this->info("Procesando: {$setName} ({$setId})...");

            $page = 1;
            $hasMorePages = true;

            while ($hasMorePages) {
                try {
                    // 🚀 AÑADIDO: Micro-descanso (0.25s) para no saturar la API
                    usleep(250000); 

                    $cardsResponse = Http::timeout(60)->retry(3, 1000)
                        ->withHeaders(['X-Api-Key' => $apiKey])
                        ->get("https://api.pokemontcg.io/v2/cards", [
                            'q' => "set.id:{$setId}",
                            'pageSize' => 250,
                            'page' => $page
                        ]);

                    if ($cardsResponse->successful()) {
                        $cards = $cardsResponse->json()['data'];

                        if (empty($cards)) {
                            $hasMorePages = false; 
                            break;
                        }

                        $dbSet = CardSet::firstOrCreate(
                            ['game_id' => $game->id, 'code' => $setId],
                            ['name' => $setName, 'total_cards' => $set['printedTotal'] ?? $set['total'] ?? null]
                        );

                        foreach ($cards as $card) {
                            // 1. Guardamos la carta y la asignamos a la variable $template
                            $template = CardTemplate::updateOrCreate(
                                ['api_id' => $card['id']], 
                                [
                                    'card_set_id' => $dbSet->id, 
                                    'unique_id' => $game->slug . '-' . $card['id'], 
                                    'name' => $card['name'],
                                    'card_number' => $card['number'] ?? null,
                                    'rarity' => $card['rarity'] ?? null, 
                                    'image_url' => $card['images']['large'] ?? null,
                                    'attributes' => [
                                        'hp' => $card['hp'] ?? null,
                                        'supertype' => $card['supertype'] ?? null,
                                        'types' => $card['types'] ?? null,
                                    ]
                                ]
                            );
                            $totalCardsImported++;

                            // 2. 🚀 LÓGICA DE PRECIOS: Extraemos el precio de Cardmarket
                            // Buscamos el averageSellPrice, y si no existe, probamos con el trendPrice
                            $marketPrice = $card['cardmarket']['prices']['averageSellPrice'] 
                                        ?? $card['cardmarket']['prices']['trendPrice'] 
                                        ?? null;

                            // Si la API nos ha devuelto un precio válido, lo guardamos en tu tabla
                            if ($marketPrice !== null) {
                                CardPrice::updateOrCreate(
                                    [
                                        'card_template_id' => $template->id,
                                        'provider' => 'cardmarket' // Así lo distingues del local_json_dump
                                    ],
                                    [
                                        'price' => $marketPrice,
                                        'currency' => 'EUR'
                                    ]
                                );
                            }
                        }

                        if (count($cards) < 250) {
                            $hasMorePages = false; 
                        } else {
                            $page++;
                        }

                    } else {
                        $this->error("Fallo de red en la página {$page} de {$setName}. Saltando...");
                        $hasMorePages = false;
                    }
                } catch (\Exception $e) {
                    // 🚀 AÑADIDO: El escudo protector. Si la API falla, captura el error y sigue adelante.
                    $this->error("Error inesperado o 404 en el set {$setName}. Saltando al siguiente...");
                    $hasMorePages = false; 
                }
            }
        }

        $this->newLine();
        $this->info("¡Sincronización masiva completada!");
        $this->info("Total de cartas procesadas: {$totalCardsImported}");
    }
}