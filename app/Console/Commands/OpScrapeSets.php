<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CardSet;
use App\Models\Game;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

class OpScrapeSets extends Command
{
    protected $signature = 'op:scrape-sets';
    protected $description = 'OP: Extrae cajas/expansiones de todas las regiones y genera JSONs';

    public function handle()
    {
        $this->info('🤖 Iniciando el recolector Multirregión de CAJAS de One Piece...');
        
        $game = Game::firstOrCreate(['slug' => 'one-piece'], ['name' => 'One Piece TCG']);

        $regions = [
            'en'      => 'https://en.onepiece-cardgame.com',
            'fr'      => 'https://fr.onepiece-cardgame.com',
            'en-asia' => 'https://asia-en.onepiece-cardgame.com',
            'tc'      => 'https://asia-tc.onepiece-cardgame.com',
            'th'      => 'https://asia-th.onepiece-cardgame.com',
            'jp'      => 'https://www.onepiece-cardgame.com',
        ];

        foreach ($regions as $regionCode => $baseUrl) {
            $this->warn("\n🌍 --- Procesando región: [{$regionCode}] ---");
            
            $page = 1;
            $hasMorePages = true;
            $jsonExportData = []; 

            while ($hasMorePages) {
                $this->line("📄 Escaneando la página {$page} de {$regionCode}...");
                
                $url = "{$baseUrl}/products/?subcategory=all&view=normal&page={$page}";
                $response = Http::get($url);

                if (!$response->successful()) {
                    $this->error("❌ Error conectando con {$baseUrl}");
                    break; 
                }

                $crawler = new Crawler($response->body());
                $boxes = $crawler->filter('.linkListColBox');

                if ($boxes->count() === 0) {
                    $this->info("🏁 Fin de la región [{$regionCode}].");
                    $hasMorePages = false;
                    break;
                }

                $boxes->each(function (Crawler $node) use ($baseUrl, $game, $regionCode, &$jsonExportData) {
                    try {
                        $title = $node->filter('.linkListColTitle')->text();
                        
                        if (preg_match('/(?:\[|【)(.*?)(?:\]|】)/u', $title, $matches)) {
                            $code = $matches[1];
                            $cleanCode = str_replace(['-', ' '], '', $code);
                            
                            $imageNode = $node->filter('img.lazy');
                            $originalImageUrl = $imageNode->attr('data-src') ?? $imageNode->attr('src');
                            if (str_starts_with($originalImageUrl, '/')) {
                                $originalImageUrl = $baseUrl . $originalImageUrl;
                            }

                            // 🚀 NUEVO: Lógica para descargar la imagen físicamente
                            $localImagePath = null;
                            if ($originalImageUrl) {
                                try {
                                    // Limpiamos la URL por si tiene parámetros basura (como ?_=...)
                                    $cleanUrl = explode('?', $originalImageUrl)[0];
                                    $imageContents = Http::get($cleanUrl)->body();
                                    
                                    // Generamos un nombre seguro: ej. "OP-16_caja.webp"
                                    $filename = 'sets/' . $code . '_' . basename($cleanUrl);
                                    
                                    // La guardamos en el disco público de Laravel
                                    Storage::disk('public')->put($filename, $imageContents);
                                    
                                    // Esta es la ruta que leerá Angular
                                    $localImagePath = '/storage/' . $filename;
                                } catch (\Exception $e) {
                                    $localImagePath = $originalImageUrl; // Si falla, dejamos la original
                                }
                            }

                            $dateNode = $node->filter('time.newsDate');
                            $releaseDate = $dateNode->count() > 0 ? $dateNode->attr('datetime') : null;

                            $cleanName = trim(preg_replace('/(?:\[|【).*?(?:\]|】)/u', '', $title));
                            $rawFamily = explode('-', $code)[0] ?? '';
                            $family = preg_replace('/[^A-Za-z]/', '', $rawFamily);

                            $set = CardSet::where('region', $regionCode)
                                          ->where(function ($query) use ($cleanCode) {
                                              $query->whereRaw("REPLACE(REPLACE(code, '-', ''), ' ', '') = ?", [$cleanCode])
                                                    ->orWhereRaw("REPLACE(REPLACE(code, '-', ''), ' ', '') LIKE ?", [$cleanCode . '%']);
                                          })->first();

                            $setData = [
                                'game_id' => $game->id,
                                'name' => $cleanName,
                                'code' => $code,
                                'region' => $regionCode,
                                'family' => $family,
                                'image_url' => $localImagePath, // 🚀 LA CORRECCIÓN ESTÁ AQUÍ
                                'release_date' => $releaseDate,
                            ];

                            if ($set) {
                                $set->update($setData);
                                $this->info("  ✔ [{$code}] actualizado.");
                            } else {
                                CardSet::create($setData);
                                $this->info("  ➕ [{$code}] CREADO.");
                            }
                            $jsonExportData[] = $setData;
                        }
                    } catch (\Exception $e) {}
                });
                $page++;
            }
            Storage::put("tcg_data/sets_{$regionCode}.json", json_encode($jsonExportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->info("\n✨ ¡Todas las regiones han sido escaneadas y se han creado todos los JSON!");
    }
}