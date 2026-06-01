<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_sets', function (Blueprint $table) {
            // Añadimos el idioma/región. Por defecto 'en' (Inglés).
            $table->string('region')->default('en')->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('card_sets', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};