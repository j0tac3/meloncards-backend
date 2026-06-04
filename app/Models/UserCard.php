<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCard extends Model
{
    use HasFactory;

    // Aquí le decimos a Laravel qué columnas puede rellenar el usuario
    protected $fillable = [
        'user_id',
        'card_template_id',
        'card_state_id',
        'language',
        'is_foil',
        'quantity',
        'is_favorite'
    ];

    protected $casts = [
        'is_foil' => 'boolean',
        'is_favorite' => 'boolean',
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function cardTemplate() {
        return $this->belongsTo(CardTemplate::class);
    }
    
    public function cardState() {
        return $this->belongsTo(CardState::class);
    }
}