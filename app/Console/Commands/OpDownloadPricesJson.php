<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;

class OpDownloadPricesJson extends Command
{
    // Este es el comando que escribirás en la terminal
    protected $signature = 'op:download-prices-json';
    protected $description = 'Descarga el catálogo completo de cartas desde OPTCG API y lo guarda en local';

    public function handle()
    {
        $this->info("🚀 Iniciando la descarga del JSON de precios...");
        $url = 'https://www.optcgapi.com/api/allSetCards/';

        $this->line("📥 Conectando con: {$url}");
        $this->line("⏳ Esto puede tardar unos segundos dependiendo del tamaño del archivo...");

        // Le damos 120 segundos de margen por si el archivo es inmenso o la API va lenta
        $response = Http::timeout(120)->get($url);

        if (!$response->successful()) {
            $this->error("❌ Error al descargar el archivo. Código HTTP: " . $response->status());
            return Command::FAILURE;
        }

        $this->line("✅ Descarga completada con éxito. Guardando en disco...");

        // Definimos la ruta exacta donde el otro comando espera encontrarlo
        $filePath = storage_path('app/one_piece_precios.json');

        // Guardamos el contenido crudo (JSON) directamente en el archivo
        File::put($filePath, $response->body());

        // Calculamos el tamaño del archivo para darle un extra de información útil
        $sizeMB = round(File::size($filePath) / 1048576, 2);

        $this->newLine();
        $this->info("🎉 ¡JSON ACTUALIZADO CORRECTAMENTE!");
        $this->line("📍 Ruta: {$filePath}");
        $this->line("⚖️ Tamaño: {$sizeMB} MB");
        
        return Command::SUCCESS;
    }
}