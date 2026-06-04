<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\CardSet;

class OpDownloadSetImages extends Command
{
    protected $signature   = 'op:download-set-images
                                {--force : Redownload images already on local server}';
    protected $description = 'Descarga imágenes de sets, las recorta y las guarda en el servidor local';

    public function handle(): int
    {
        $this->info('🖼  Iniciando descarga y recorte de carátulas...');

        $query = CardSet::query();

        // Sin --force: solo las que aún apuntan a URLs externas
        if (!$this->option('force')) {
            $query->where('image_url', 'like', 'http%');
        }

        $sets = $query->get();

        if ($sets->isEmpty()) {
            $this->info('✅ No hay imágenes nuevas que descargar. Todo está al día.');
            return self::SUCCESS;
        }

        // ── Verificar que storage:link ya existe (no llamarlo en cada ejecución) ──
        $linkPath = public_path('storage');
        if (!file_exists($linkPath)) {
            $this->call('storage:link');
        }

        $bar      = $this->output->createProgressBar($sets->count());
        $errores  = 0;
        $ok       = 0;
        $bar->start();

        foreach ($sets as $set) {
            try {
                $result = $this->processSetImage($set);

                if ($result) {
                    $ok++;
                } else {
                    $errores++;
                }

            } catch (\Throwable $e) {
                $errores++;
                $this->newLine();
                $this->error("❌ Error en [{$set->code}]: " . $e->getMessage());
                Log::error('op:download-set-images failed', [
                    'set_code' => $set->code,
                    'error'    => $e->getMessage(),
                ]);
            }

            $bar->advance();

            // Pausa para no saturar el servidor de origen
            sleep(1);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✨ Completado. Descargadas: {$ok} | Errores: {$errores}");

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function processSetImage(CardSet $set): bool
    {
        $imageUrl = $set->image_url;

        // Construir nombre de archivo limpio
        $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'webp';
        $cleanCode = Str::slug($set->code);
        $filename  = "{$cleanCode}_{$set->region}.{$extension}";
        $storagePath = "sets/{$filename}";

        // Descargar imagen con reintentos
        $imageContents = Http::retry(3, 2000)
            ->timeout(30)
            ->get($imageUrl)
            ->throw() // Lanza excepción si status >= 400
            ->body();

        // Guardar en storage/app/public/sets/
        Storage::disk('public')->put($storagePath, $imageContents);

        // Recortar con Node.js (auto-recortar.js)
        $absolutePath = Storage::disk('public')->path($storagePath);
        $nodePath     = config('scraper.node_script_path')
            ? dirname(config('scraper.node_script_path')) . '/auto-recortar.js'
            : base_path('../cardmarket-scraper/auto-recortar.js');

        $resultado = Process::timeout(30)->run(
            "node " . escapeshellarg($nodePath) . " " . escapeshellarg($absolutePath)
        );

        if (!$resultado->successful()) {
            // El recorte es opcional: logamos la advertencia pero no falla el proceso
            $this->newLine();
            $this->warn("⚠️  No se pudo recortar [{$set->code}]: " . $resultado->errorOutput());
            Log::warning('auto-recortar failed', [
                'set_code' => $set->code,
                'output'   => $resultado->errorOutput(),
            ]);
        }

        // Actualizar BD con la ruta local
        $set->update(['image_url' => "/storage/{$storagePath}"]);

        return true;
    }
}
