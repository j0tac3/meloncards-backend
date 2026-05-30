<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\CardSet;
use App\Models\CardTemplate;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Creamos la Franquicia / Juego
        $pokemonGame = Game::create([
            'name' => 'Pokémon TCG',
            'slug' => 'pokemon'
        ]);
        
        // ¡Bonus! Puedes añadir One Piece fácilmente para probar el futuro
        $onePieceGame = Game::create([
            'name' => 'One Piece Card Game',
            'slug' => 'one-piece'
        ]);
    }
}