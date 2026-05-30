<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\CardTemplate;
use App\Models\Game;
use App\Models\CardSet; // <-- IMPORTANTE: Añadimos el modelo del Set

class FetchPokemonCards extends Command
{
    protected $signature = 'pokemon:fetch-base-set';
    protected $description = 'Descarga las cartas del Base Set de Pokémon y las guarda en la BD';

    public function handle()
    {
        // 1. Buscamos el ID del juego Pokémon
        $game = Game::where('slug', 'pokemon')->first();

        if (!$game) {
            $this->error('Error: El juego Pokémon no existe. ¡Ejecuta los seeders primero!');
            return;
        }

        $this->info('Conectando con la API de Pokémon TCG...');

        // 1. Rescatamos la clave segura del archivo .env
        $apiKey = env('POKEMONTCG_API_KEY');

        // 2. Hacemos la petición inyectando la clave en las cabeceras (Headers)
        $response = Http::timeout(60)
            ->retry(3, 1000)
            ->withHeaders([
                'X-Api-Key' => $apiKey, // 👈 Aquí inyectamos el pase VIP
            ])
            ->get('https://api.pokemontcg.io/v2/cards?q=set.id:base1');

        if ($response->successful()) {
            $cards = $response->json()['data'];
            $bar = $this->output->createProgressBar(count($cards));

            $bar->start();

            // 3. Recorremos las cartas y las guardamos
            foreach ($cards as $card) {
                
                // --- LA MAGIA MULTIJUEGO ---
                // Buscamos si el Set (ej. Base Set) ya existe. Si no, lo crea automáticamente.
                // Usamos el id de la API (base1) para guardarlo en nuestro campo 'code'
                $set = CardSet::firstOrCreate(
                    [
                        'game_id' => $game->id,
                        'code' => $card['set']['id'] // ej. "base1"
                    ],
                    [
                        'name' => $card['set']['name'], // ej. "Base"
                        // La API a veces trae 'printedTotal' o 'total'
                        'total_cards' => $card['set']['printedTotal'] ?? $card['set']['total'] ?? null,
                    ]
                );

                // --- GUARDAMOS LA CARTA ---
                CardTemplate::updateOrCreate(
                    ['api_id' => $card['id']], // Tu genial idea en acción
                    [
                        'card_set_id' => $set->id, // Vinculamos la carta al Set (La nueva estructura)
                        'unique_id' => $game->slug . '-' . $card['id'],
                        'name' => $card['name'],
                        'card_number' => $card['number'] ?? null,
                        'rarity' => $card['rarity'] ?? null, // Lo pasamos a su propia columna física
                        'image_url' => $card['images']['small'] ?? null,
                        
                        // Los datos específicos del juego van a tu columna JSON
                        'attributes' => [
                            'hp' => $card['hp'] ?? null,
                            'supertype' => $card['supertype'] ?? null,
                            'types' => $card['types'] ?? null,
                        ]
                    ]
                );

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info('¡Sincronización completada! ' . count($cards) . ' cartas procesadas.');

        } else {
            $this->error('Error al conectar con la API de Pokémon.');
        }
    }
}