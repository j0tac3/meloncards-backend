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

        // 2. Creamos la Expansión (Asignada a Pokémon)
        $baseSet = CardSet::create([
            'game_id' => $pokemonGame->id,
            'name' => 'Base Set',
            'code' => 'BS',
            'total_cards' => 102
        ]);

        // 3. Creamos las cartas con nuestra nueva columna JSON 'attributes'
        CardTemplate::create([
            'card_set_id' => $baseSet->id,
            'name' => 'Charizard',
            'card_number' => '4/102',
            'rarity' => 'Rare Holo',
            'image_url' => 'https://images.pokemontcg.io/base1/4_hires.png',
            'attributes' => [
                'hp' => 120,
                'types' => ['Fire'],
                'stage' => 'Stage 2',
                'weakness' => 'Water'
            ]
        ]);

        CardTemplate::create([
            'card_set_id' => $baseSet->id,
            'name' => 'Blastoise',
            'card_number' => '2/102',
            'rarity' => 'Rare Holo',
            'image_url' => 'https://images.pokemontcg.io/base1/2_hires.png',
            'attributes' => [
                'hp' => 100,
                'types' => ['Water'],
                'stage' => 'Stage 2',
                'weakness' => 'Lightning'
            ]
        ]);
        
        // ¡Bonus! Puedes añadir One Piece fácilmente para probar el futuro
        $onePieceGame = Game::create([
            'name' => 'One Piece Card Game',
            'slug' => 'one-piece'
        ]);

        CardSet::create([
            'game_id' => $onePieceGame->id,
            'name' => 'Romance Dawn',
            'code' => 'OP-01',
            'total_cards' => 121
        ]);
    }
}