<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Llamamos a nuestros seeders específicos
        $this->call([
            CardStateSeeder::class,
            GameSeeder::class,
        ]);
    }
}