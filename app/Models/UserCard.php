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
        'card_state_id', // <-- 1. AÑADE ESTO
        'language',
        'is_foil',
        'quantity',
    ];

    // ... (Tus relaciones se quedan igual) ...
    public function user() {
        return $this->belongsTo(User::class);
    }

    public function cardTemplate() {
        return $this->belongsTo(CardTemplate::class);
    }
    
    // Y no olvides añadir la relación hacia el estado si no la tenías
    public function cardState() {
        return $this->belongsTo(CardState::class);
    }
}