<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardTemplate extends Model
{
    // 1. DESACTIVAMOS EL FILTRO: Permite guardar las nuevas columnas de golpe
    protected $guarded = [];

    protected $fillable = ['card_set_id', 'api_id', 'name', 'unique_id', 'card_number', 'rarity', 'image_url', 'attributes'];

    // 2. Magia del JSON
    protected $casts = [
        'attributes' => 'array', 
    ];

    // 3. La Relación
    public function cardSet()
    {
        return $this->belongsTo(CardSet::class);
    }

    public function prices()
    {
        return $this->hasMany(CardPrice::class);
    }

    // O si guardas un historial (relación 1 a N) y quieres el último:
    public function latestPrice()
    {
        return $this->hasOne(CardPrice::class, 'card_template_id')->latestOfMany();
    }

    /**
     * Usuarios que tienen esta carta en su lista de deseos.
     */
    public function wishlistedByUsers()
    {
        return $this->belongsToMany(\App\Models\User::class, 'wishlists', 'card_id', 'user_id');
    }
}