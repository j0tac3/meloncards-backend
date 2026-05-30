<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CardStateSeeder extends Seeder
{
    public function run(): void
    {
        $states = [
            ['name' => 'Mint / Near Mint', 'slug' => 'near-mint', 'description' => 'Carta en estado perfecto o casi perfecto, como recién salida del sobre.'],
            ['name' => 'Excellent / Lightly Played', 'slug' => 'lightly-played', 'description' => 'Con marcas mínimas de uso, algún borde ligeramente blanco.'],
            ['name' => 'Good / Moderately Played', 'slug' => 'moderately-played', 'description' => 'Desgaste notable en bordes y esquinas, posibles rasguños.'],
            ['name' => 'Played / Heavily Played', 'slug' => 'heavily-played', 'description' => 'Desgaste severo, dobleces o manchas muy evidentes.'],
            ['name' => 'Damaged', 'slug' => 'damaged', 'description' => 'Roturas, dobleces críticas, marcas de agua o daños estructurales graves.']
        ];

        DB::table('card_states')->insert($states);
    }
}