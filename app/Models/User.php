<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // 1. IMPORTANTE: Añadir esta importación

class User extends Authenticatable
{
    // 2. IMPORTANTE: Añadir HasApiTokens al principio de esta lista
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Recuerda que aquí abajo tienes tu función userCards() que añadimos antes
    public function userCards()
    {
        return $this->hasMany(UserCard::class);
    }

    /**
     * Cartas que el usuario ha añadido a su lista de deseos.
     */
    /**
     * Cartas que el usuario ha añadido a su lista de deseos.
     */
    public function wishlistedCards()
    {
        return $this->belongsToMany(
            \App\Models\CardTemplate::class, 
            'wishlists', // 1. El nombre real de tu tabla pivote
            'user_id',   // 2. La columna para el usuario
            'card_id'    // 3. La columna para la carta
        )->withTimestamps();
    }
}