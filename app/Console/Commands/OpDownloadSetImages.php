<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CardSet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpDownloadSetImages extends Command
{
    protected $signature = 'op:download-set-images';
    protected $description = 'Descarga las imágenes de Bandai y las guarda en el servidor local';

    public function handle()
    {
        $this->info('🖼 Iniciando descarga de carátulas...');

        // Buscamos solo los sets cuya URL empiece por "http" (es decir, las de Bandai). 
        // Si ya las hemos descargado, empezarán por "/storage/...", así que las ignorará y ahorramos tiempo.
        $sets = CardSet::where('image_url', 'like', 'http%')->get();

        if ($sets->isEmpty()) {
            $this->info('✅ No hay imágenes nuevas que descargar. Todo está al día.');
            return;
        }

        $bar = $this->output->createProgressBar(count($sets));
        $bar->start();

        foreach ($sets as $set) {
            try {
                // Descargamos la imagen de Bandai
                $imageContents = Http::get($set->image_url)->body();

                // Creamos un nombre limpio (ej: op-15_en.webp)
                $extension = pathinfo(parse_url($set->image_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'webp';
                $cleanCode = Str::slug($set->code); // Convierte "OP-15" en "op-15"
                $filename = "{$cleanCode}_{$set->region}.{$extension}";

                // La guardamos físicamente en storage/app/public/sets/
                Storage::disk('public')->put("sets/{$filename}", $imageContents);

                // Actualizamos la base de datos para que apunte a NUESTRO servidor
                $set->update([
                    'image_url' => "/storage/sets/{$filename}"
                ]);

                // Pausa de 1 segundo para no saturar al servidor de Bandai y que no nos bloquee
                sleep(1); 

            } catch (\Exception $e) {
                $this->error("\n❌ Error descargando [{$set->code}]: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        
        // Comando mágico de Laravel para que las imágenes sean públicas en la web
        $this->call('storage:link'); 
        
        $this->info("\n✨ ¡Todas las imágenes han sido descargadas en tu servidor de forma segura!");
    }
}