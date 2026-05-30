<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('card_prices', function (Blueprint $table) {
            $table->id();
            
            // Relación con tu carta física (Asumo que tu modelo se llama CardTemplate)
            $table->foreignId('card_template_id')->constrained()->onDelete('cascade');
            
            $table->decimal('price', 8, 2)->nullable(); // El precio (Ej: 15.50)
            $table->string('currency', 3)->default('USD'); // Moneda: 'USD' o 'EUR'
            $table->string('provider')->nullable(); // Fuente: 'tcgplayer', 'cardmarket', 'github'
            
            $table->timestamps();

            // Índice para hacer las búsquedas súper rápidas
            $table->index(['card_template_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_prices');
    }
};
