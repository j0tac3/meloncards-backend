<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Game;
use App\Models\CardSet;
use App\Models\CardTemplate;
use App\Models\Region; 

class OpImportCards extends Command
{
    protected $signature = 'op:import-cards';
    protected $description = 'OP: Lee los JSON locales de cartas y los fusiona en la Base de Datos';

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
            $this->error('❌ No hay archivos JSON. Ejecuta op:scrape-cards primero.');
            return;
        }

        $allCardIds = array_unique($allCardIds);
        $this->info('🗃️ Total de cartas únicas a fusionar: ' . count($allCardIds));

        // 🚀 1. VALIDACIÓN DEL JUEGO
        $game = Game::where('slug', 'one-piece')->first();
        
        if (!$game) {
            $this->error('❌ El juego One Piece no existe en la BD. Ejecuta primero: php artisan system:setup');
            return;
        }

        $bar = $this->output->createProgressBar(count($allCardIds));

        foreach ($allCardIds as $uniqueId) {
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

            $cardNumber = $mainSource['id'] ?? explode('_', $uniqueId)[0];
            $fallbackName = $mainSource['name'] ?? 'Unknown';
            $fallbackImage = $mainSource['image_url'] ?? '';
            $fallbackSetName = $mainSource['set_name'] ?? 'Expansión ' . explode('-', $cardNumber)[0];

            preg_match('/(?:\[|【)(.*?)(?:\]|】)/u', $fallbackSetName, $matches);
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

            // 🚀 BÚSQUEDA INTELIGENTE DE CAJAS
            // Vinculamos la carta a la caja en inglés por defecto, o a la que haya.
            $set = CardSet::where('code', $realSetCode)->where('region', 'en')->first();
            if (!$set) $set = CardSet::where('code', $realSetCode)->first();
            if (!$set) {
                $cleanName = trim(preg_replace('/(?:\[|【).*?(?:\]|】)/u', '', $fallbackSetName));
                $set = CardSet::create([
                    'game_id' => $game->id, 'name' => $cleanName, 'code' => $realSetCode, 'region' => 'en'
                ]);
            }

            $baseAttributes = array_filter($baseAttributes, fn($val) => $val !== null && $val !== '');
            $finalAttributes = array_merge($baseAttributes, $localizedAttributes);

            CardTemplate::updateOrCreate(
                ['unique_id' => $uniqueId], // 🚀 BÚSQUEDA: Solo miramos si la carta existe en toda la BD
                [
                    'card_set_id' => $set->id, // 🚀 ACTUALIZACIÓN: Si existe, la movemos a su caja correcta
                    'card_number' => $cardNumber, 
                    'name' => $fallbackName,
                    'image_url' => $fallbackImage,
                    'attributes' => $finalAttributes
                ]
            );
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        
        $this->info('🧮 Sincronizando el número total de cartas en todas las regiones...');
        
        // 1. Obtenemos todos los códigos únicos de las expansiones (ej: OP-16, ST-05)
        $uniqueCodes = CardSet::where('game_id', $game->id)->distinct()->pluck('code');
        
        foreach ($uniqueCodes as $code) {
            // 2. Buscamos la caja "maestra" (la inglesa, que es la que se quedó las cartas)
            $masterSet = CardSet::where('code', $code)->where('region', 'en')->first() 
                      ?? CardSet::where('code', $code)->first();
            
            if ($masterSet) {
                // 3. Contamos cuántas cartas tiene la caja maestra
                $count = CardTemplate::where('card_set_id', $masterSet->id)->count();
                
                // 4. 🚀 MAGIA: Le ponemos ese total a TODAS las cajas del mundo con ese código
                CardSet::where('code', $code)->update(['total_cards' => $count]);
            }
        }

        $this->info('🎉 ¡BD Reconstruida: Juego, Regiones y Cartas sincronizadas!');
    }
}