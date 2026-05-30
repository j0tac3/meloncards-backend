<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class DownloadOnePieceAssets extends Command
{
    protected $signature = 'tcg:download-assets';
    protected $description = 'Descarga imágenes con sistema Auto-Retry incorporado';

    public function handle()
    {
        $this->info('🚀 Iniciando el Gestor Universal de Descargas BLINDADO...');

        $files = Storage::disk('local')->files();
        $basePath = 'One_Piece_Collection';

        foreach ($files as $file) {
            if (preg_match('/^one_piece_(.+)\.json$/', $file, $matches)) {
                $regionName = $matches[1];
                $this->newLine();
                $this->warn("🌍 Procesando Idioma: [ {$regionName} ]");

                $cards = json_decode(Storage::disk('local')->get($file), true) ?? [];
                
                foreach ($cards as $card) {
                    if (empty($card['image_url'])) continue;

                    $setCode = explode('-', $card['id'])[0] ?? 'PROMO';
                    $safeSetCode = trim(preg_replace('/[\\\\\/\:\*\?\"\<\>\|]/', '-', $setCode));
                    
                    $imagesPath = "{$basePath}/{$safeSetCode}/{$regionName}/images";
                    Storage::disk('local')->makeDirectory($imagesPath);

                    $url = $card['image_url'];
                    $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
                    $fileName = $card['id'] . '.' . $extension;
                    $imageFullPath = "{$imagesPath}/{$fileName}";

                    if (!Storage::disk('local')->exists($imageFullPath)) {
                        try {
                            // 🛡️ SISTEMA DE AUTO-REINTENTO (3 Strikes, 5s espera) nativo de Laravel
                            $response = Http::retry(3, 5000)
                                ->withHeaders([
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
                                ])->timeout(30)->get($url);

                            if ($response->successful()) {
                                Storage::disk('local')->put($imageFullPath, $response->body());
                                usleep(250000); // 250ms de pausa natural para no saturar
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
        $this->info('🎉 ¡ÉXITO! Todas las descargas finalizadas con seguridad.');
    }
}