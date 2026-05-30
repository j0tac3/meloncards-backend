<?php

namespace App\Http\Controllers;

use App\Models\CardTemplate;
use Illuminate\Http\Request;

class CardCatalogController extends Controller
{
    // Obtener todas las cartas (con buscador y paginación)
    public function index(Request $request)
    {
        // 1. Consulta base con Eager Loading (Cargamos Set y Precios)
        // 🚀 NUEVO: Añadimos 'prices' al with()
        $query = \App\Models\CardTemplate::with(['cardSet', 'prices']); 

        // Filtro por nombre
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // El filtro por Expansión (Set)
        if ($request->filled('card_set_id')) {
            $query->where('card_set_id', $request->card_set_id);
        }

        // 🚀 NUEVO: El filtro por Juego (Buscando a través del Set)
        if ($request->filled('game_id')) {
            $query->whereHas('cardSet', function($q) use ($request) {
                $q->where('game_id', $request->game_id);
            });
        }

        $cards = $query->paginate(15);

        // --- TRUCO DE INFRAESTRUCTURA: OPTIMIZACIÓN EN LOTE ---
        $user = $request->user('sanctum');
        $ownedQuantities = [];

        if ($user) {
            $cardIdsInPage = $cards->pluck('id')->toArray();

            $ownedQuantities = $user->userCards()
                ->whereIn('card_template_id', $cardIdsInPage)
                ->select('card_template_id', \DB::raw('SUM(quantity) as total_quantity'))
                ->groupBy('card_template_id')
                ->pluck('total_quantity', 'card_template_id')
                ->toArray(); 
        }

        // 3. Cruzamos los datos y extraemos la info
        $cards->getCollection()->transform(function ($card) use ($ownedQuantities) {
            $card->set_name = $card->cardSet ? $card->cardSet->name : 'Unknown';
            $card->set_total = $card->cardSet ? $card->cardSet->total_cards : 0;
            $card->owned_copies = $ownedQuantities[$card->id] ?? 0;
            
            // 🚀 NUEVO: Extraemos el precio en Euros
            // (Asumimos que puede haber varios precios, cogemos el primero porque solo hemos guardado EUR)
            $precio = $card->prices->first();
            $card->market_price = $precio ? (float) $precio->price : null;
            
            return $card;
        });

        return response()->json($cards);
    }

    // Ver el detalle de una carta específica
    public function show($id)
    {
        $card = CardTemplate::findOrFail($id);
        return response()->json($card);
    }
}