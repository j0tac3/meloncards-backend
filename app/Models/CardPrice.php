<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardPrice extends Model
{
    // Damos permiso a Laravel para escribir en estas columnas
    protected $fillable = [
        'card_template_id',
        'price',
        'currency',
        'provider'
    ];

    public function template()
    {
        return $this->belongsTo(CardTemplate::class, 'card_template_id');
    }
}