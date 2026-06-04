<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\CardTemplate;
use App\Models\CardPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class OpScrapePricesCm extends Command
{
    protected $signature   = 'op:scrape-prices-cm
                                {set? : Slug de la expansión en Cardmarket (ej: Romance-Dawn)}
                                {--game=OnePiece : Juego (OnePiece, Pokemon, etc.)}
                                {--pages=1 : Número máximo de páginas a raspar}';
    protected $description = 'Extrae precios de una expansión entera en Cardmarket';

    // ── Detectar Chrome automáticamente (igual que en OpScrapeCards) ──────────
    private function getChromePath(): ?string
    {
        $paths = [
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
            // Windows (dev local) — solo como último recurso
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) return $path;
        }
        return null;
    }

    public function handle(): int
    {
        $setSlug   = $this->argument('set') ?? 'Romance-Dawn';
        $game      = $this->option('game');
        $maxPages  = (int) $this->option('pages');
        $chromePath = $this->getChromePath();

        if ($chromePath) {
            $this->line("⚙️  Chrome detectado en: {$chromePath}");
        }

        $this->info("🥷 Iniciando infiltración en Cardmarket → {$game}/{$setSlug}...");

        // Pre-cargar mapa card_number → id en memoria (evita N queries en el bucle)
        $cardMap = CardTemplate::pluck('id', 'card_number')->toArray();
        $this->line("🧠 " . count($cardMap) . " cartas cargadas en memoria.");

        $guardadas  = 0;
        $errores    = 0;
        $priceBatch = []; // acumulador para upsert en lote

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = "https://www.cardmarket.com/en/{$game}/Products/Singles/{$setSlug}?site={$page}";
            $this->line("📄 Página {$page}: {$url}");

            $html = $this->fetchPage($url, $chromePath);

            if ($html === null) {
                $this->error("❌ No se pudo obtener la página {$page}. Abortando.");
                break;
            }

            // Debug: guardar HTML solo en entorno local
            if (app()->isLocal()) {
                File::put(storage_path("app/debug_cm_p{$page}.html"), $html);
            }

            $crawler = new Crawler($html);
            $filas   = $crawler->filter('.table-body .row');

            if ($filas->count() === 0) {
                $this->warn("⚠️  Sin resultados en página {$page}. Cloudflare o fin de lista.");
                break;
            }

            $this->line("   ✅ {$filas->count()} filas encontradas.");

            $filas->each(function (Crawler $item) use (&$priceBatch, &$errores, $cardMap) {
                try {
                    $linkTag = $item->filter('.col-10.col-md-8 a, .col-sellers-offers a');
                    if ($linkTag->count() === 0) return;

                    $nombreCompleto = trim($linkTag->text());

                    // Extraer código de carta entre paréntesis: (OP01-001)
                    if (!preg_match('/\(([A-Z0-9\-]+)\)/i', $nombreCompleto, $m)) return;
                    $cardNumber = $m[1];

                    // Precio: buscar el primer elemento con clase price
                    $precioTag = $item->filter('.col-price, .price-container');
                    if ($precioTag->count() === 0) return;

                    $cleanPrice = preg_replace('/[^0-9,]/', '', $precioTag->first()->text());
                    $priceValue = (float) str_replace(',', '.', $cleanPrice);

                    if ($priceValue <= 0 || !isset($cardMap[$cardNumber])) return;

                    // Acumular para upsert en lote
                    $priceBatch[] = [
                        'card_template_id' => $cardMap[$cardNumber],
                        'currency'         => 'EUR',
                        'provider'         => 'cardmarket',
                        'price'            => $priceValue,
                        'updated_at'       => now(),
                        'created_at'       => now(),
                    ];

                } catch (\Throwable $e) {
                    $errores++;
                    Log::warning('op:scrape-prices-cm row parse error', ['error' => $e->getMessage()]);
                }
            });

            // Pausa entre páginas para no activar rate-limits
            if ($page < $maxPages) sleep(rand(2, 4));
        }

        // ── Upsert del lote completo en UNA sola transacción ─────────────────
        if (!empty($priceBatch)) {
            DB::beginTransaction();
            try {
                // Lotes de 200 para no superar límite de placeholders de MySQL
                foreach (array_chunk($priceBatch, 200) as $chunk) {
                    CardPrice::upsert(
                        $chunk,
                        ['card_template_id', 'currency', 'provider'],
                        ['price', 'updated_at']
                    );
                    $guardadas += count($chunk);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error('❌ Error al guardar precios. Rollback ejecutado.');
                $this->error($e->getMessage());
                Log::error('op:scrape-prices-cm upsert failed', ['exception' => $e]);
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info("🎉 Completado. Precios guardados: {$guardadas} | Errores de parseo: {$errores}");
        return self::SUCCESS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function fetchPage(string $url, ?string $chromePath): ?string
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $browser = Browsershot::url($url)
                    ->noSandbox()
                    ->windowSize(1920, 1080)
                    ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36')
                    ->setExtraHttpHeaders([
                        'Accept-Language'           => 'es-ES,es;q=0.9,en-US;q=0.8,en;q=0.7',
                        'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Upgrade-Insecure-Requests' => '1',
                    ])
                    ->addBrowserArgs([
                        '--disable-blink-features=AutomationControlled',
                        '--disable-infobars',
                        '--ignore-certificate-errors',
                        '--lang=es-ES,es,en',
                    ])
                    ->waitUntilNetworkIdle()
                    ->timeout(60);

                if ($chromePath) $browser->setChromePath($chromePath);

                return $browser->bodyHtml();

            } catch (\Throwable $e) {
                $this->warn("⚠️  Intento {$attempt}/{$maxRetries} fallido: " . $e->getMessage());
                if ($attempt < $maxRetries) sleep(5);
            }
        }

        return null;
    }
}
