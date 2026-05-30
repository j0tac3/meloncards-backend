<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('game_region', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            
            // Evitamos que se duplique la misma región en un mismo juego
            $table->unique(['game_id', 'region_id']); 
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('game_region');
    }
};