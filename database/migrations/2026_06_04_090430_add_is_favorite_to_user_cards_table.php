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
        Schema::table('user_cards', function (Blueprint $table) {
            // Añadimos la columna por defecto a false
            $table->boolean('is_favorite')->default(false)->after('quantity');
        });
    }

    public function down()
    {
        Schema::table('user_cards', function (Blueprint $table) {
            $table->dropColumn('is_favorite');
        });
    }
};
