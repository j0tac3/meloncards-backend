<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_sets', function (Blueprint $table) {
            $table->id();
            // Relación con el Juego (One Piece, Pokemon...)
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // "Base Set"
            $table->string('code')->nullable(); // "BS"
            $table->integer('total_cards')->nullable(); // 102
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_sets');
    }
};