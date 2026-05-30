<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\CardTemplate;
use App\Models\CardPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File; // ¡Añadimos esta línea!

class UpdatePrices extends Command
{
    protected $signature = 'tcg:update-prices';
    protected $description = 'Sincroniza masivamente los precios desde un JSON local y convierte a EUR';

    public function handle()
    {
        $this->info("🚀 Iniciando volcado masivo de precios (Local Dump)...");

        // 1. Obtener el tipo de cambio actual (USD -> EUR)
        // Esto lo dejamos porque es instantáneo y necesario para calcular el Euro real de hoy
        $this->line("💶 Consultando el Banco Central Europeo (Frankfurter API)...");
        $currencyResponse = Http::timeout(10)->get('https://api.frankfurter.app/latest?from=USD&to=EUR');
        
        if (!$currencyResponse->successful()) {
            $this->error("❌ Fallo al obtener el tipo de cambio.");
            return;
        }
        $usdToEurRate = $currencyResponse->json('rates.EUR');
        $this->line("   ✅ Tipo de cambio obtenido: 1 USD = {$usdToEurRate} EUR");

        // 2. LEER EL ARCHIVO LOCAL (Adiós a la API externa)
        $this->line("📦 Leyendo catálogo desde el archivo local...");
        
        // Asumimos que tienes el archivo en storage/app/one_piece_precios.json
        // Cámbiale el nombre aquí si lo llamaste de otra forma
        $filePath = storage_path('app/one_piece_precios.json'); 

        if (!File::exists($filePath)) {
            $this->error("❌ No se encuentra el archivo JSON en: " . $filePath);
            $this->line("Asegúrate de haberlo guardado en la carpeta storage/app/ con ese nombre.");
            return;
        }

        // Decodificamos el JSON local
        $apiCards = json_decode(File::get($filePath), true);
        $this->line("   ✅ Leídas " . count($apiCards) . " cartas del archivo local.");

        // 3. Cargar mapa de IDs en memoria
        $localCardsMap = CardTemplate::pluck('id', 'unique_id')->toArray();
        $this->line("🧠 Cargadas " . count($localCardsMap) . " cartas locales en memoria para procesado ultra-rápido.");

        $bar = $this->output->createProgressBar(count($apiCards));
        $preciosActualizados = 0;

        // 4. Procesar y guardar
        // 4. Procesar y guardar
        DB::beginTransaction(); 
        try {
            foreach ($apiCards as $apiCard) {
                // Sacamos el ID y lo limpiamos de espacios accidentales
                $rawId = $apiCard['card_image_id'] ?? $apiCard['card_set_id'] ?? null;
                if (!$rawId) continue;
                
                $uniqueId = trim($rawId);
                
                // Aseguramos que el precio sea un número flotante válido
                $priceUsd = (float) ($apiCard['market_price'] ?? 0);

                // Si la carta tiene precio real y la tenemos en nuestra Base de Datos local
                if ($priceUsd > 0 && isset($localCardsMap[$uniqueId])) {
                    
                    $priceEur = round($priceUsd * $usdToEurRate, 2);
                    $internalCardId = $localCardsMap[$uniqueId];

                    CardPrice::updateOrCreate(
                        [
                            'card_template_id' => $internalCardId,
                            'currency' => 'EUR', // Tu app mostrará el precio en Euros gracias a tu conversión
                        ],
                        [
                            'price' => $priceEur,
                            'provider' => 'optcgapi_tcgplayer', // 🚀 Mejorado para identificar el mercado
                            'updated_at' => now(),
                        ]
                    );
                    $preciosActualizados++;
                }
                $bar->advance();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("\n❌ Error durante el guardado en BD: " . $e->getMessage());
            return;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("🎉 ¡PROCESO COMPLETADO!");
        $this->line("✅ Se han actualizado/creado los precios de {$preciosActualizados} cartas en Euros.");
    }
}