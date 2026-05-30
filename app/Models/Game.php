<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    // 1. Campos que permitimos rellenar
    protected $fillable = [
        'name',
        'slug',
    ];

    // 2. Relación: Un juego tiene muchas cartas plantilla (Catálogo)
    public function cardTemplates()
    {
        return $this->hasMany(CardTemplate::class);
    }

    public function regions()
    {
        return $this->belongsToMany(Region::class);
    }
}