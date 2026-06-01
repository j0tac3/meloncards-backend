<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Game;
use App\Models\Region;

class OpSystemSetup extends Command
{
    protected $signature = 'op:system-setup';
    protected $description = 'OP: Inicializa los datos estructurales (Juego y Regiones)';

    public function handle()
    {
        $this->info('⚙️ Iniciando la configuración base de One Piece...');

        // 1. CONFIGURACIÓN DEL JUEGO
        $this->line('🎮 Creando el juego en la base de datos...');
        $opGame = Game::firstOrCreate(['slug' => 'one-piece'], ['name' => 'One Piece TCG']);

        // 2. CONFIGURACIÓN DE REGIONES
        $this->line('🌍 Creando idiomas y regiones...');
        $regionsConfig = [
            'en' => 'Global (Inglés)',
            'jp' => 'Japón (Japonés)',
            'fr' => 'Francia (Francés)',
            'asia-en' => 'Asia (Inglés)',
            'asia-tc' => 'Asia (Chino Tradicional)',
            'asia-th' => 'Asia (Tailandés)'
        ];

        $regionIds = [];
        foreach ($regionsConfig as $code => $name) {
            $region = Region::firstOrCreate(['code' => $code], ['name' => $name]);
            $regionIds[] = $region->id;
        }

        // 3. VINCULACIÓN
        $this->line('🔗 Vinculando regiones al juego...');
        $opGame->regions()->sync($regionIds);

        $this->newLine();
        $this->info('✨ ¡Configuración estructural de OP completada! El sistema está listo para importar datos.');
    }
}