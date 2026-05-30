<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Relación: Una región pertenece a muchos juegos
    public function games()
    {
        return $this->belongsToMany(Game::class);
    }
}