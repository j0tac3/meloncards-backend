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
    Schema::create('card_templates', function (Blueprint $table) {
        $table->id();
        
        $table->foreignId('card_set_id')->constrained()->cascadeOnDelete();
        $table->string('api_id')->unique()->nullable(); 
        $table->string('name');
        $table->string('unique_id')->unique();
        $table->string('card_number')->nullable(); // ej. "004/102"
        $table->string('rarity')->nullable();      // ej. "Rare Holo"
        $table->string('image_url')->nullable();
        $table->jsonb('attributes')->nullable(); 
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_templates');
    }
};
