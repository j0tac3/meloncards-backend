<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class OpScrapeCards extends Command
{
    protected $signature = 'op:scrape-cards {region : jp, asia-en, asia-tc, asia-th, en, fr, o all}';
    protected $description = 'Motor Universal Blindado: Extrae idiomas con sistema de Auto-Reintento';

    protected $regionsConfig = [
        'jp'      => 'www.onepiece-cardgame.com',
        'asia-tc' => 'asia-tc.onepiece-cardgame.com',
        'asia-en' => 'asia-en.onepiece-cardgame.com',
        'asia-th' => 'asia-th.onepiece-cardgame.com',
        'en'      => 'en.onepiece-cardgame.com',
        'fr'      => 'fr.onepiece-cardgame.com',
    ];

    // 🚀 NUEVO: Radar automático para encontrar el navegador en cualquier servidor Linux
    // 🚀 NUEVO: Radar automático ajustado para priorizar Chrome
    private function getChromePath()
    {
        $paths = [
            '/usr/bin/google-chrome',    // 🚀 1. Buscamos el Chrome de verdad primero
            '/usr/bin/chromium',         // 2. Por si acaso
            '/usr/bin/chromium-browser', // 3. El fantasma de Snap lo dejamos para el final
            '/snap/bin/chromium'         
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return null; 
    }
    public function handle()
    {
        $regionArg = $this->argument('region');
        
        $regionsToRun = [];
        if ($regionArg === 'all') {
            $regionsToRun = array_keys($this->regionsConfig);
        } elseif (isset($this->regionsConfig[$regionArg])) {
            $regionsToRun = [$regionArg];
        } else {
            $this->error("❌ Región no válida. Usa: " . implode(', ', array_keys($this->regionsConfig)));
            return;
        }

        $chromePath = $this->getChromePath();
        if ($chromePath) {
            $this->info("⚙️  Navegador detectado automáticamente en: " . $chromePath);
        }

        foreach ($regionsToRun as $regionCode) {
            $domain = $this->regionsConfig[$regionCode];
            $this->info("🌍 INICIANDO EXTRACCIÓN: Región [{$regionCode}] -> {$domain}");

            $this->line("   🔍 Leyendo el menú de expansiones de Bandai...");
            $seriesMap = $this->fetchSeriesMap($domain, $chromePath);

            if (empty($seriesMap)) {
                $this->error("   ❌ No se encontraron expansiones para {$regionCode}.");
                continue;
            }

            $this->info("   ✅ Se han descubierto " . count($seriesMap) . " expansiones. Empezando descarga...");

            $allCardsData = [];
            $jsonFileName = "one_piece_{$regionCode}.json";

            if (Storage::disk('local')->exists($jsonFileName)) {
                $allCardsData = json_decode(Storage::disk('local')->get($jsonFileName), true) ?? [];
            }

            $index = 1;
            foreach ($seriesMap as $seriesId => $seriesName) {
                $this->warn("📦 PROCESANDO: {$seriesName} ({$index}/" . count($seriesMap) . ")");
                
                $colorsToSearch = ['']; 
                
                try {
                    $testUrl = "https://{$domain}/cardlist/?series={$seriesId}";
                    
                    $browser = Browsershot::url($testUrl)
                        ->noSandbox()
                        ->waitUntilNetworkIdle()
                        ->timeout(60);
                    
                    if ($chromePath) $browser->setChromePath($chromePath);
                    
                    $testHtml = $browser->bodyHtml();
                        
                    if (str_contains($testHtml, 'Too many search results') || str_contains($testHtml, 'too many')) {
                        $this->warn("      ⚠️ Colección masiva detectada. Dividiendo por colores...");
                        $colorsToSearch = ['1', '2', '3', '4', '5', '6'];
                    }
                } catch (\Exception $e) {
                    $this->error("      ❌ Error de comprobación: " . $e->getMessage());
                    continue;
                }

                foreach ($colorsToSearch as $colorCode) {
                    $page = 1;
                    $previousState = ''; 

                    while (true) {
                        $colorParam = $colorCode !== '' ? "&color={$colorCode}" : '';
                        $currentUrl = "https://{$domain}/cardlist/?series={$seriesId}{$colorParam}&page={$page}";
                        $this->line("      📄 Leyendo pág {$page}" . ($colorCode !== '' ? " (Color {$colorCode})" : "") . "...");

                        $html = null;
                        $maxRetries = 3;
                        $success = false;

                        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                            try {
                                $browserLoop = Browsershot::url($currentUrl)
                                    ->noSandbox()
                                    ->waitUntilNetworkIdle()
                                    ->timeout(60);
                                
                                if ($chromePath) $browserLoop->setChromePath($chromePath);
                                
                                $html = $browserLoop->bodyHtml();
                                $success = true;
                                break; 
                            } catch (\Exception $e) {
                                if ($attempt === $maxRetries) {
                                    $this->error("      ❌ Fallo tras 3 intentos en pág {$page}: " . $e->getMessage());
                                } else {
                                    $this->warn("      ⚠️ Corte de Bandai. Reintentando en 5s (Intento {$attempt}/{$maxRetries})...");
                                    sleep(5);
                                }
                            }
                        }

                        if (!$success) break; 

                        $crawler = new Crawler($html);
                        $cartasEnEstaPagina = []; 
                        
                        $crawler->filter('.resultCol > dl, .resultCol > div')->each(function (Crawler $node) use (&$allCardsData, &$cartasEnEstaPagina, $seriesName, $domain) {
                            try {
                                $cardNumber = $node->filter('.infoCol span')->count() > 0 ? trim($node->filter('.infoCol span')->text()) : null;
                                if (!$cardNumber) return;

                                $cartasEnEstaPagina[] = $cardNumber;
                                $infoParts = explode('|', $node->filter('.infoCol')->count() > 0 ? $node->filter('.infoCol')->text() : '');
                                
                                $hasLife = $node->filter('.life')->count() > 0;
                                $isLeader = $hasLife;

                                $rawName = $node->filter('.cardName')->count() > 0 ? $node->filter('.cardName')->text() : 'Unknown';
                                $cleanName = html_entity_decode(trim($rawName), ENT_QUOTES, 'UTF-8');

                                $imgNode = $node->filter('img'); 
                                $imageRelativeUrl = $imgNode->count() > 0 ? ($imgNode->attr('data-src') ?: $imgNode->attr('src')) : '';
                                $imageRelativeUrl = explode('?', $imageRelativeUrl)[0];
                                
                                $filename = basename(parse_url($imageRelativeUrl, PHP_URL_PATH));
                                $uniqueId = preg_replace('/\.[^.]+$/', '', $filename);
                                if (empty($uniqueId)) $uniqueId = $cardNumber;

                                $imageUrl = str_starts_with($imageRelativeUrl, 'http') ? $imageRelativeUrl : "https://{$domain}/" . ltrim(str_replace('../', '', $imageRelativeUrl), '/');

                                $cost = null;
                                if (!$isLeader && $node->filter('.cost')->count() > 0) {
                                    if (preg_match('/\d+/', $node->filter('.cost')->text(), $matches)) $cost = (int)$matches[0];
                                }
                                $power = null;
                                if ($node->filter('.power')->count() > 0) {
                                    if (preg_match('/\d+/', $node->filter('.power')->text(), $matches)) $power = (int)$matches[0];
                                }
                                $life = null;
                                if ($hasLife) {
                                    if (preg_match('/\d+/', $node->filter('.life')->text(), $matches)) $life = (int)$matches[0];
                                }

                                $allCardsData[$uniqueId] = [
                                    'unique_id' => $uniqueId,
                                    'id' => $cardNumber,      
                                    'name' => $cleanName,
                                    'set_name' => $seriesName,
                                    'image_url' => $imageUrl,
                                    'cost' => $cost,
                                    'power' => $power,
                                    'life' => $life,
                                    'category' => isset($infoParts[2]) ? trim($infoParts[2]) : ($node->filter('.category')->count() > 0 ? trim($node->filter('.category')->text()) : null),
                                    'rarity' => isset($infoParts[1]) ? trim($infoParts[1]) : ($node->filter('.rarity')->count() > 0 ? trim($node->filter('.rarity')->text()) : null),
                                    'color' => $node->filter('.color')->count() > 0 ? trim(str_replace(['Color', 'Couleur', '色'], '', $node->filter('.color')->text())) : null,
                                    'attribute' => $node->filter('.attribute')->count() > 0 ? trim(str_replace(['Attribute', 'Attribut', '属性'], '', $node->filter('.attribute')->text())) : null,
                                    'counter' => $node->filter('.counter')->count() > 0 ? trim(str_replace(['Counter', 'Contre', 'カウンター'], '', $node->filter('.counter')->text())) : null,
                                    'feature' => $node->filter('.feature')->count() > 0 ? trim(str_replace(['Type', '特徴'], '', $node->filter('.feature')->text())) : null,
                                    'effect' => $node->filter('.text')->count() > 0 ? trim(str_replace(['Effect', 'Effet', 'テキスト'], '', $node->filter('.text')->text())) : null,
                                ];
                            } catch (\Exception $e) {}
                        });

                        $currentState = implode(',', $cartasEnEstaPagina);
                        if (empty($cartasEnEstaPagina) || $currentState === $previousState) {
                            break; 
                        }
                        $previousState = $currentState;

                        Storage::disk('local')->put($jsonFileName, json_encode($allCardsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        $page++;
                        sleep(1);
                    }
                }
                $index++;
            }
            $this->newLine();
            $this->info("🎉 Región {$regionCode} FINALIZADA.");
        }
    }

    private function fetchSeriesMap($domain, $chromePath)
    {
        $seriesMap = [];
        $url = "https://{$domain}/cardlist/";

        try {
            $browserMenu = Browsershot::url($url)
                ->noSandbox()
                ->waitUntilNetworkIdle()
                ->timeout(60);
            
            if ($chromePath) $browserMenu->setChromePath($chromePath);
            
            $html = $browserMenu->bodyHtml();

            $crawler = new Crawler($html);
            $crawler->filter('li.selModalClose, select[name="series"] option')->each(function (Crawler $node) use (&$seriesMap) {
                $val = $node->attr('data-value') ?: $node->attr('value');
                if (is_numeric($val) && $val > 0) {
                    $name = trim(preg_replace('/\s+/', ' ', $node->text()));
                    $seriesMap[$val] = $name;
                }
            });
        } catch (\Exception $e) {
            $this->error("💥 Error obteniendo el menú: " . $e->getMessage());
        }
        
        return $seriesMap;
    }
}