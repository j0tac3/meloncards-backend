<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            // Vinculamos al usuario
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // Vinculamos a la carta (Asegúrate de que tu tabla de cartas se llame 'cards', si no, cámbialo aquí)
            $table->foreignId('card_id')->constrained('card_templates')->onDelete('cascade');
            
            $table->timestamps();

            // 🛡️ Regla de oro: Un usuario no puede desear la misma carta dos veces
            $table->unique(['user_id', 'card_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
