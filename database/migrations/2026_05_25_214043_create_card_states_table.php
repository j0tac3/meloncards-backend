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
        Schema::create('card_states', function (Blueprint $table) {
            $table->id();
            $table->string('name');        // ej: Near Mint
            $table->string('slug')->unique(); // ej: near-mint
            $table->text('description');   // La explicación para el icono (i)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_states');
    }
};
