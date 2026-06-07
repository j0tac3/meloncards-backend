<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class OpDownloadCardImages extends Command
{
    protected $signature = 'op:download-card-images';
    protected $description = 'Descarga imágenes en la carpeta public para ser servidas directamente';

    public function handle()
    {
        $this->info('🚀 Iniciando el Gestor de Descargas (Directo a PUBLIC)...');

        // Los JSON los leemos del disco 'local' (storage/app)
        $files = Storage::disk('local')->files();

        foreach ($files as $file) {
            if (preg_match('/^one_piece_(.+)\.json$/', $file, $matches)) {
                $regionName = $matches[1];
                $this->newLine();
                $this->warn("🌍 Procesando Idioma: [ {$regionName} ]");

                // Leemos el JSON
                $jsonContent = Storage::disk('local')->get($file);
                $cards = json_decode($jsonContent, true) ?? [];
                
                foreach ($cards as $card) {
                    if (empty($card['image_url'])) continue;

                    // Sacamos el ID del Set
                    $setCode = explode('-', $card['id'])[0] ?? 'PROMO';
                    $safeSetCode = trim(preg_replace('/[\\\\\/\:\*\?\"\<\>\|]/', '-', $setCode));
                    
                    // 🚀 RUTA BASE EN PUBLIC: storage/app/public/cards/OP01/en/
                    $imagesPath = "cards/{$safeSetCode}/{$regionName}";
                    Storage::disk('public')->makeDirectory($imagesPath);

                    $url = $card['image_url'];
                    $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
                    $fileName = $card['id'] . '.' . $extension;
                    
                    // Ruta final del archivo
                    $imageFullPath = "{$imagesPath}/{$fileName}";

                    // Comprobamos si YA existe en el disco public
                    if (!Storage::disk('public')->exists($imageFullPath)) {
                        try {
                            $response = Http::retry(3, 5000)
                                ->withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                                ])->timeout(30)->get($url);

                            if ($response->successful()) {
                                // Guardamos en el disco public
                                Storage::disk('public')->put($imageFullPath, $response->body());
                                usleep(250000); // 250ms de pausa
                                $this->line("      ⬇️ Descargada: {$fileName}");
                            } else {
                                $this->error("      ❌ Fallo final al descargar {$fileName}");
                            }
                        } catch (\Exception $e) {
                            $this->error("      ❌ Error crítico en {$fileName}: " . $e->getMessage());
                        }
                    }
                }
                $this->info("   ✅ Idioma {$regionName} sincronizado al 100%.");
            }
        }
        $this->newLine();
        $this->info('🎉 ¡ÉXITO! Todas las descargas finalizadas en la carpeta public.');
        $this->warn('👉 IMPORTANTE: Ejecuta "php artisan storage:link" si no lo has hecho aún.');
    }
}