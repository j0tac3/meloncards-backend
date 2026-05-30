<?php

namespace App\Console\Commands;

use Illuminate\Command\Command; // Asegúrate de que esta línea esté bien importada
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeBandai extends Command
{
    protected $signature = 'tcg:scrape-bandai {url : La URL base de la serie (sin el parámetro page)}';
    protected $description = 'Extrae todas las páginas de cartas usando Chrome real';

    public function handle()
    {
        $baseUrl = $this->argument('url');
        
        // Si la URL no tiene ? para añadir parámetros, lo añadimos
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        
        $page = 1;
        $allCardsData = []; // Aquí iremos guardando todo
        
        $this->info("🚀 Iniciando extracción masiva desde: {$baseUrl}");

        while (true) {
            $this->info("📄 Procesando página {$page}...");
            
            // Construimos la URL completa para esta página
            $currentUrl = "{$baseUrl}{$separator}page={$page}";
            
            try {
                $html = Browsershot::url($currentUrl)
                    ->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')
                    ->waitUntilNetworkIdle()
                    ->timeout(60)
                    ->bodyHtml();

                $crawler = new Crawler($html);
                $cardsOnThisPage = 0;

                $crawler->filter('.resultCol > dl, .resultCol > div')->each(function (Crawler $node) use (&$allCardsData, &$cardsOnThisPage) {
                    try {
                        $cardNumber = $node->filter('.infoCol span')->count() > 0 
                            ? trim($node->filter('.infoCol span')->text()) 
                            : null;

                        if (!$cardNumber) return;

                        $rawName = $node->filter('.cardName')->count() > 0 ? $node->filter('.cardName')->text() : 'Unknown';
                        $cleanName = html_entity_decode(trim($rawName), ENT_QUOTES, 'UTF-8');

                        $imageRelativeUrl = $node->filter('.imageCol img')->count() > 0 ? $node->filter('.imageCol img')->attr('src') : '';
                        $imageUrl = 'https://asia-en.onepiece-cardgame.com' . ltrim($imageRelativeUrl, '.');

                        $costText = $node->filter('.cost')->count() > 0 ? $node->filter('.cost')->text() : null;
                        $powerText = $node->filter('.power')->count() > 0 ? $node->filter('.power')->text() : null;

                        $allCardsData[$cardNumber] = [
                            'id' => $cardNumber,
                            'name' => $cleanName,
                            'image_url' => $imageUrl,
                            'cost' => $costText ? (int) preg_replace('/[^0-9]/', '', $costText) : null,
                            'power' => $powerText ? (int) preg_replace('/[^0-9]/', '', $powerText) : null,
                        ];
                        
                        $cardsOnThisPage++;
                    } catch (\Exception $e) {}
                });

                $this->info("   ✅ Encontradas {$cardsOnThisPage} cartas.");

                // CONDICIÓN DE PARADA: Si esta página no tiene cartas, hemos terminado
                if ($cardsOnThisPage === 0) {
                    $this->info("🏁 No se encontraron más cartas. Fin del proceso.");
                    break;
                }

                $page++;
                sleep(1); // Pequeña pausa para no saturar a Bandai

            } catch (\Exception $e) {
                $this->error("❌ Error en página {$page}: " . $e->getMessage());
                break; // Si falla algo, paramos aquí
            }
        }

        // Guardamos el JSON masivo
        Storage::disk('local')->put('one_piece_database.json', json_encode($allCardsData, JSON_PRETTY_PRINT));
        $this->info('🎉 ¡ÉXITO TOTAL! Se han guardado ' . count($allCardsData) . ' cartas en storage/app/one_piece_database.json');
    }
}