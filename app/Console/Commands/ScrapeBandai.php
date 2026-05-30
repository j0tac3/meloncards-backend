<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeBandai extends Command
{
    protected $signature = 'tcg:scrape-bandai';
    protected $description = 'Extrae la versión ASIA (Japón/Asia-English) del catálogo';

    public function handle()
    {
        $this->info("🚀 Iniciando la ARAÑA DEFINITIVA en versión ASIA...");

        // IDs asiáticos (Prefijo 556)
        $seriesMap = [
            '556302' => 'PREMIUM BOOSTER -ONE PIECE CARD THE BEST vol.2- [PRB-02]',
            '556301' => 'PREMIUM BOOSTER -ONE PIECE CARD THE BEST- [PRB-01]',
            '556204' => 'EXTRA BOOSTER -EGGHEAD CRISIS- [EB-04]',
            '556203' => 'EXTRA BOOSTER -ONE PIECE Heroines Edition- [EB-03]',
            '556202' => 'EXTRA BOOSTER -Anime 25th collection- [EB-02]',
            '556201' => 'EXTRA BOOSTER -Memorial Collection- [EB-01]',
            '556115' => 'BOOSTER PACK -Adventure on KAMI’s Island- [OP-15]',
            '556114' => 'BOOSTER PACK -The Azure Sea’s Seven- [OP-14]',
            '556113' => 'BOOSTER PACK -Carrying on His Will- [OP-13]',
            '556112' => 'BOOSTER PACK -Legacy of the Master- [OP-12]',
            '556111' => 'BOOSTER PACK -A Fist of Divine Speed- [OP-11]',
            '556110' => 'BOOSTER PACK -Royal Blood- [OP-10]',
            '556109' => 'BOOSTER PACK -Emperors in the New World- [OP-09]',
            '556108' => 'BOOSTER PACK -Two Legends- [OP-08]',
            '556107' => 'BOOSTER PACK -500 Years in the Future- [OP-07]',
            '556106' => 'BOOSTER PACK -Wings of Captain- [OP-06]',
            '556105' => 'BOOSTER PACK -Awakening of the New Era- [OP-05]',
            '556104' => 'BOOSTER PACK -Kingdoms of Intrigue- [OP-04]',
            '556103' => 'BOOSTER PACK -Pillars of Strength- [OP-03]',
            '556102' => 'BOOSTER PACK -Paramount War- [OP-02]',
            '556101' => 'BOOSTER PACK -ROMANCE DAWN- [OP-01]',
            '556030' => 'STARTER DECK EX -Luffy & Ace- [ST-30]',
            '556029' => 'STARTER DECK -EGGHEAD- [ST-29]',
            '556028' => 'STARTER DECK -Green/Yellow Yamato- [ST-28]',
            '556027' => 'STARTER DECK -Black Marshall.D.Teach- [ST-27]',
            '556026' => 'STARTER DECK -Purple/Black Monkey.D.Luffy- [ST-26]',
            '556025' => 'STARTER DECK -Blue Buggy- [ST-25]',
            '556024' => 'STARTER DECK -Green Jewelry Bonney- [ST-24]',
            '556023' => 'STARTER DECK -Red Shanks- [ST-23]',
            '556022' => 'STARTER DECK -Ace & Newgate- [ST-22]',
            '556021' => 'STARTER DECK EX -GEAR5- [ST-21]',
            '556020' => 'STARTER DECK -Yellow Charlotte Katakuri- [ST-20]',
            '556019' => 'STARTER DECK -Black Smoker- [ST-19]',
            '556018' => 'STARTER DECK -Purple Monkey.D.Luffy- [ST-18]',
            '556017' => 'STARTER DECK -Blue Donquixote Doflamingo- [ST-17]',
            '556016' => 'STARTER DECK -Green Uta- [ST-16]',
            '556015' => 'STARTER DECK -Red Edward.Newgate- [ST-15]',
            '556014' => 'STARTER DECK -3D2Y- [ST-14]',
            '556013' => 'ULTIMATE DECK -The Three Brothers Bond- [ST-13]',
            '556012' => 'STARTER DECK -Zoro & Sanji- [ST-12]',
            '556011' => 'STARTER DECK -Side Uta- [ST-11]',
            '556010' => 'ULTIMATE DECK -The Three Captains- [ST-10]',
            '556009' => 'STARTER DECK -Side Yamato- [ST-09]',
            '556008' => 'STARTER DECK -Side Monkey.D.Luffy- [ST-08]',
            '556007' => 'STARTER DECK -Big Mom Pirates- [ST-07]',
            '556006' => 'STARTER DECK -The Navy- [ST-06]',
            '556005' => 'STARTER DECK -ONE PIECE FILM edition- [ST-05]',
            '556004' => 'STARTER DECK -Animal Kingdom Pirates- [ST-04]',
            '556003' => 'STARTER DECK -The Seven Warlords of the Sea- [ST-03]',
            '556002' => 'STARTER DECK -Worst Generation- [ST-02]',
            '556001' => 'STARTER DECK -Straw Hat Crew- [ST-01]',
            '556701' => 'Family Deck Set',
            '556901' => 'Promotion card',
            '556801' => 'Limited Product Card'
        ];

        $allCardsData = [];
        if (Storage::disk('local')->exists('one_piece_asia.json')) {
            $allCardsData = json_decode(Storage::disk('local')->get('one_piece_asia.json'), true) ?? [];
        }

        $index = 1;
        foreach ($seriesMap as $seriesId => $seriesName) {
            $this->newLine();
            $this->warn("📦 PROCESANDO: {$seriesName} ({$index} de " . count($seriesMap) . ")");
            
            $colorsToSearch = ['']; 
            
            try {
                $testHtml = Browsershot::url("https://asia-en.onepiece-cardgame.com/cardlist/?series={$seriesId}&page=1")
                    ->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')
                    ->waitUntilNetworkIdle()->timeout(60)->bodyHtml();
                    
                if (str_contains($testHtml, 'Too many search results')) {
                    $this->warn("   ⚠️ Colección masiva detectada. Dividiendo búsqueda...");
                    $colorsToSearch = ['1', '2', '3', '4', '5', '6'];
                }
            } catch (\Exception $e) {
                $this->error("   ❌ Error: " . $e->getMessage());
                continue;
            }

            foreach ($colorsToSearch as $colorCode) {
                $page = 1;
                $previousState = ''; 

                while (true) {
                    $colorParam = $colorCode !== '' ? "&color={$colorCode}" : '';
                    $currentUrl = "https://asia-en.onepiece-cardgame.com/cardlist/?series={$seriesId}{$colorParam}&page={$page}";
                    $this->line("   📄 Leyendo página {$page}" . ($colorCode !== '' ? " (Color {$colorCode})" : "") . "...");

                    try {
                        $html = Browsershot::url($currentUrl)
                            ->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')
                            ->waitUntilNetworkIdle()->timeout(60)->bodyHtml();

                        $crawler = new Crawler($html);
                        $cartasEnEstaPagina = []; 
                        
                        $crawler->filter('.resultCol > dl, .resultCol > div')->each(function (Crawler $node) use (&$allCardsData, &$cartasEnEstaPagina, $seriesName) {
                            try {
                                $cardNumber = $node->filter('.infoCol span')->count() > 0 ? trim($node->filter('.infoCol span')->text()) : null;
                                if (!$cardNumber) return;

                                $cartasEnEstaPagina[] = $cardNumber;

                                $infoParts = explode('|', $node->filter('.infoCol')->count() > 0 ? $node->filter('.infoCol')->text() : '');
                                $category = isset($infoParts[2]) ? trim($infoParts[2]) : ($node->filter('.category')->count() > 0 ? trim($node->filter('.category')->text()) : null);
                                $isLeader = (strtoupper($category) === 'LEADER');

                                $rawName = $node->filter('.cardName')->count() > 0 ? $node->filter('.cardName')->text() : 'Unknown';
                                $cleanName = html_entity_decode(trim($rawName), ENT_QUOTES, 'UTF-8');

                                $imgNode = $node->filter('img'); 
                                $imageRelativeUrl = $imgNode->count() > 0 ? ($imgNode->attr('data-src') ?: $imgNode->attr('src')) : '';
                                $imageRelativeUrl = explode('?', $imageRelativeUrl)[0];
                                $imageUrl = str_starts_with($imageRelativeUrl, 'http') ? $imageRelativeUrl : 'https://asia-en.onepiece-cardgame.com/' . ltrim(str_replace('../', '', $imageRelativeUrl), '/');

                                $cost = null;
                                if (!$isLeader && $node->filter('.cost')->count() > 0) {
                                    if (preg_match('/\d+/', $node->filter('.cost')->text(), $matches)) $cost = (int)$matches[0];
                                }
                                $power = null;
                                if ($node->filter('.power')->count() > 0) {
                                    if (preg_match('/\d+/', $node->filter('.power')->text(), $matches)) $power = (int)$matches[0];
                                }
                                $life = null;
                                if ($node->filter('.life')->count() > 0) {
                                    if (preg_match('/\d+/', $node->filter('.life')->text(), $matches)) $life = (int)$matches[0];
                                }

                                $allCardsData[$cardNumber] = [
                                    'id' => $cardNumber,
                                    'name' => $cleanName,
                                    'set_name' => $seriesName,
                                    'image_url' => $imageUrl,
                                    'cost' => $cost,
                                    'power' => $power,
                                    'life' => $life,
                                    'category' => $category,
                                    'rarity' => isset($infoParts[1]) ? trim($infoParts[1]) : ($node->filter('.rarity')->count() > 0 ? trim($node->filter('.rarity')->text()) : null),
                                    'color' => $node->filter('.color')->count() > 0 ? trim(str_replace('Color', '', $node->filter('.color')->text())) : null,
                                    'attribute' => $node->filter('.attribute')->count() > 0 ? trim(str_replace('Attribute', '', $node->filter('.attribute')->text())) : null,
                                    'counter' => $node->filter('.counter')->count() > 0 ? trim(str_replace('Counter', '', $node->filter('.counter')->text())) : null,
                                    'feature' => $node->filter('.feature')->count() > 0 ? trim(str_replace('Type', '', $node->filter('.feature')->text())) : ($node->filter('.type')->count() > 0 ? trim(str_replace('Type', '', $node->filter('.type')->text())) : null),
                                    'effect' => $node->filter('.text')->count() > 0 ? trim(str_replace('Effect', '', $node->filter('.text')->text())) : null,
                                ];
                            } catch (\Exception $e) {}
                        });

                        $currentState = implode(',', $cartasEnEstaPagina);
                        if (empty($cartasEnEstaPagina) || $currentState === $previousState) {
                            $this->line("      🏁 Fin de resultados para esta búsqueda.");
                            break; 
                        }
                        $previousState = $currentState;

                        Storage::disk('local')->put('one_piece_asia.json', json_encode($allCardsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        $page++;
                        sleep(1);

                    } catch (\Exception $e) {
                        break;
                    }
                }
            }
            $index++;
        }

        $this->info('🎉 ¡CATÁLOGO ASIA FINALIZADO! Total: ' . count($allCardsData));
    }
}