<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardSet extends Model
{
    // 1. Permitir guardar datos de golpe sin errores de asignación masiva
    protected $fillable = [
        'game_id', 
        'name', 
        'code', 
        'region',
        'total_cards', 
        'family', 
        'release_date', 
        'image_url'
    ];

    // 2. RELACIÓN: Un Set pertenece a un Juego
    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    // 3. RELACIÓN: Un Set contiene muchas cartas
    public function cardTemplates()
    {
        return $this->hasMany(CardTemplate::class);
    }
}