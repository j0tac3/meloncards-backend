<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Game;
use App\Models\CardSet;
use App\Models\CardTemplate;
use App\Models\Region; 

class ImportOnePieceCards extends Command
{
    protected $signature = 'tcg:import-one-piece';
    protected $description = 'Escanea todos los JSON locales y los fusiona en MySQL priorizando el inglés en la raíz';

    public function handle()
    {
        $this->info('📦 Escaneando JSONs de idiomas disponibles...');

        $files = Storage::disk('local')->files();
        $regionData = [];
        $allCardIds = [];

        foreach ($files as $file) {
            if (preg_match('/^one_piece_(.+)\.json$/', $file, $matches)) {
                $region = $matches[1];
                $data = json_decode(Storage::disk('local')->get($file), true) ?? [];
                $regionData[$region] = $data;
                $allCardIds = array_merge($allCardIds, array_keys($data));
                $this->line("   ✅ JSON [{$region}] cargado: " . count($data) . " cartas.");
            }
        }

        if (empty($regionData)) {
            $this->error('❌ No hay archivos JSON en storage/app/. Ejecuta tcg:scrape-region primero.');
            return;
        }

        $allCardIds = array_unique($allCardIds);
        $this->info('🗃️ Total de cartas únicas a fusionar: ' . count($allCardIds));

        // 🚀 1. GESTIÓN DEL JUEGO Y SUS REGIONES (Tabla Pivote)
        $game = Game::firstOrCreate(['slug' => 'one-piece'], ['name' => 'One Piece TCG']);
        
        $regionsConfig = [
            'en' => 'Global (Inglés)',
            'jp' => 'Japón (Japonés)',
            'fr' => 'Francia (Francés)',
            'asia-en' => 'Asia (Inglés)',
            'asia-tc' => 'Asia (Chino Tradicional)',
            'asia-th' => 'Asia (Tailandés)'
        ];

        $regionIds = [];
        foreach ($regionsConfig as $code => $name) {
            $region = Region::firstOrCreate(['code' => $code], ['name' => $name]);
            $regionIds[] = $region->id;
        }
        
        // Sincroniza las regiones con el juego en la tabla game_region
        $game->regions()->sync($regionIds);
        // --------------------------------------------------------

        $bar = $this->output->createProgressBar(count($allCardIds));

        foreach ($allCardIds as $uniqueId) {
            
            // Buscar la fuente principal de la carta
            $mainSource = null;
            if (isset($regionData['en'][$uniqueId])) {
                $mainSource = $regionData['en'][$uniqueId];
            } elseif (isset($regionData['asia-en'][$uniqueId])) {
                $mainSource = $regionData['asia-en'][$uniqueId];
            } else {
                foreach ($regionData as $region => $cards) {
                    if (isset($cards[$uniqueId])) {
                        $mainSource = $cards[$uniqueId];
                        break;
                    }
                }
            }

            // 🚀 Extraemos el ID oficial de los datos de la carta
            $cardNumber = $mainSource['id'] ?? explode('_', $uniqueId)[0];
            
            $fallbackName = $mainSource['name'] ?? 'Unknown';
            $fallbackImage = $mainSource['image_url'] ?? '';
            $fallbackSetName = $mainSource['set_name'] ?? 'Expansión ' . explode('-', $cardNumber)[0];

            // 🚀 NUEVO: Extraemos el código real de la expansión de los corchetes (ej. PRB-02)
            preg_match('/\[(.*?)\]/', $fallbackSetName, $matches);
            $realSetCode = $matches[1] ?? explode('-', $cardNumber)[0] ?? 'PROMO';

            $localizedAttributes = [
                'name' => [], 'effect' => [], 'image_url' => [], 
                'category' => [], 'feature' => [], 'rarity' => []
            ];
            $baseAttributes = [];

            foreach ($regionData as $region => $cards) {
                if (isset($cards[$uniqueId])) {
                    $c = $cards[$uniqueId];
                    
                    $localizedAttributes['name'][$region] = $c['name'];
                    $localizedAttributes['effect'][$region] = $c['effect'];
                    $localizedAttributes['image_url'][$region] = $c['image_url'];
                    $localizedAttributes['category'][$region] = $c['category'];
                    $localizedAttributes['feature'][$region] = $c['feature'];
                    $localizedAttributes['rarity'][$region] = $c['rarity'];

                    if (empty($baseAttributes)) {
                        $baseAttributes = [
                            'cost' => $c['cost'], 'power' => $c['power'], 'life' => $c['life'],
                            'color' => $c['color'], 'attribute' => $c['attribute'], 'counter' => $c['counter']
                        ];
                    }
                }
            }

            // 🚀 EL CAMBIO CRÍTICO: Agrupamos estrictamente por nombre, no por código.
            $set = CardSet::firstOrCreate(
                ['game_id' => $game->id, 'name' => $fallbackSetName],
                ['code' => $realSetCode]
            );

            $baseAttributes = array_filter($baseAttributes, fn($val) => $val !== null && $val !== '');
            $finalAttributes = array_merge($baseAttributes, $localizedAttributes);

            // Usamos unique_id como la clave para no sobrescribir Alt-Arts
            CardTemplate::updateOrCreate(
                ['card_set_id' => $set->id, 'unique_id' => $uniqueId], 
                [
                    'card_number' => $cardNumber, 
                    'name' => $fallbackName,
                    'image_url' => $fallbackImage,
                    'attributes' => $finalAttributes
                ]
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();
        
        // 🚀 NUEVO: Recuento automático de `total_cards` al finalizar
        $this->info('🧮 Calculando el número total de cartas por expansión...');
        $sets = CardSet::where('game_id', $game->id)->get();
        $updatedSets = 0;
        foreach ($sets as $set) {
            $count = CardTemplate::where('card_set_id', $set->id)->count();
            if ($count > 0) {
                $set->update(['total_cards' => $count]);
                $updatedSets++;
            }
        }
        $this->line("   ✅ Se ha actualizado el recuento en {$updatedSets} colecciones.");

        $this->newLine();
        $this->info('🎉 ¡BD Reconstruida: Juego, Regiones y Cartas sincronizadas!');
    }
}