<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\CardTemplate;
use App\Models\CardPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class OpScrapePricesCm extends Command
{
    // Ya no hace falta el --limit, porque vamos a escanear solo una página de la expansión
    protected $signature = 'op:scrape-prices-cm';
    protected $description = 'Extrae precios de una expansión entera en Cardmarket (Estrategia de Infiltración)';

    public function handle()
    {
        $this->info("🥷 Iniciando infiltración: Accediendo al catálogo de 'Romance Dawn'...");

        // URL directa de la primera página de la expansión Romance Dawn en Cardmarket
        $url = "https://www.cardmarket.com/en/OnePiece/Products/Singles/Romance-Dawn";

        try {
            $this->line("   Navegando de incógnito a: {$url}");

            // Navegamos simulando un Chrome real
            // 👻 NAVEGACIÓN STEALTH (Bypass de Cloudflare)
            $browser = Browsershot::url($url)
                ->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')
                ->windowSize(1920, 1080)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36')
                // 1. Cabeceras súper realistas
                ->setExtraHttpHeaders([
                    'Accept-Language' => 'es-ES,es;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Upgrade-Insecure-Requests' => '1'
                ])
                // 2. Apagamos los chivatos de automatización (El antídoto)
                ->addBrowserArgs([
                    '--disable-blink-features=AutomationControlled', // Oculta el navigator.webdriver
                    '--disable-infobars',
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--ignore-certificate-errors',
                    '--lang=es-ES,es,en'
                ])
                ->waitUntilNetworkIdle()
                ->timeout(60);

            // 📸 Guardamos captura y HTML por si necesitamos hacer autopsia del muro de seguridad
            //$browser->save(storage_path("app/debug_expansion.png"));
            $html = $browser->bodyHtml();
            File::put(storage_path('app/debug_expansion.html'), $html);

            $crawler = new Crawler($html);

            // En las tablas de Cardmarket, cada fila es un div con la clase 'row' dentro de 'table-body'
            $filas = $crawler->filter('.table-body .row');

            $totalEncontrado = $filas->count();
            
            if ($totalEncontrado === 0) {
                $this->error("\n❌ No se detectaron cartas. Cloudflare nos ha cerrado la puerta.");
                $this->line("Revisa la imagen 'debug_expansion.png' en storage/app/ para ver qué pantalla nos ha saltado.");
                return;
            }

            $this->info("\n✅ Muro superado. Se han encontrado {$totalEncontrado} resultados en la página. Extrayendo datos...\n");

            $guardadas = 0;

            // Recorremos cada fila de la lista
            foreach ($filas as $nodo) {
                $item = new Crawler($nodo);
                
                try {
                    // Extraemos el nombre que incluye el código, Ej: "Roronoa Zoro (OP01-001)" o "Krieg (OP01-066)"
                    $nombreTag = $item->filter('.col-10.col-md-8 a');
                    if ($nombreTag->count() === 0) continue; // Si no hay enlace, no es una carta (puede ser la cabecera de la tabla)
                    
                    $nombreCompleto = $nombreTag->text();
                    
                    // Extraemos el precio, Ej: "12,50 €"
                    $precioTag = $item->filter('.col-price');
                    if ($precioTag->count() === 0) continue;

                    $precioTexto = $precioTag->text();
                    
                    // 🧠 MAGIA REGEX: Buscamos cualquier cosa que esté entre paréntesis, ej: OP01-001
                    preg_match('/\((.*?)\)/', $nombreCompleto, $matches);
                    $cardNumber = $matches[1] ?? null;

                    if ($cardNumber) {
                        // Limpiamos la moneda y lo pasamos a decimal de PHP
                        $cleanPrice = preg_replace('/[^0-9,]/', '', $precioTexto);
                        $cleanPrice = str_replace(',', '.', $cleanPrice);
                        $priceValue = (float) $cleanPrice;

                        // Buscamos la carta en tu base de datos local
                        $card = CardTemplate::where('card_number', $cardNumber)->first();

                        if ($card && $priceValue > 0) {
                            // GUARDADO SEGURO MULTI-MERCADO
                            DB::beginTransaction();
                            CardPrice::updateOrCreate(
                                [
                                    'card_template_id' => $card->id,
                                    'currency' => 'EUR',
                                    'provider' => 'cardmarket',
                                ],
                                [
                                    'price' => $priceValue,
                                    'updated_at' => now(),
                                ]
                            );
                            DB::commit();
                            
                            $this->line("   💶 Guardado: {$cardNumber} -> {$priceValue} €");
                            $guardadas++;
                        }
                    }
                } catch (\Exception $e) {
                    // Si una fila falla (publicidad, etc.), pasamos a la siguiente en silencio
                }
            }

            $this->newLine();
            $this->info("🎉 ¡Infiltración completada! Se han guardado los precios exactos de {$guardadas} cartas de Romance Dawn.");

        } catch (\Exception $e) {
            $this->error("\n⚠️ Error crítico de conexión: " . $e->getMessage());
        }
    }
}