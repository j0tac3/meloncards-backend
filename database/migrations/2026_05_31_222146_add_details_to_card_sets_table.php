<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_sets', function (Blueprint $table) {
            // Añadimos las 3 columnas nuevas. 
            // Las ponemos 'nullable' para que las expansiones que ya tienes guardadas no den error al estar vacías por ahora.
            $table->string('family')->nullable()->after('code'); // Para guardar: OP, ST, EB, P...
            $table->date('release_date')->nullable()->after('family'); // Para ordenar cronológicamente
            $table->string('image_url')->nullable()->after('release_date'); // Para la carátula
        });
    }

    public function down(): void
    {
        Schema::table('card_sets', function (Blueprint $table) {
            // Por si algún día queremos deshacer este paso
            $table->dropColumn(['family', 'release_date', 'image_url']);
        });
    }
};