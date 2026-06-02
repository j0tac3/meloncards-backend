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
        // Filtro por nombre o código
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $cleanTerm = str_replace(['-', ' ', '–'], '', $searchTerm);

            // 1. Empezamos con lo que escribió el usuario y la versión sin guiones
            $variations = [$searchTerm, $cleanTerm];

            // 2. Inteligencia de códigos Promo (Ej: "p103" -> "p-103")
            // Si son letras seguidas de números, inyectamos el guion en medio
            if (preg_match('/^([a-zA-Z]+)(\d+)$/', $cleanTerm, $matches)) {
                $variations[] = $matches[1] . '-' . $matches[2];
            }

            // 3. Inteligencia de códigos de Expansión (Ej: "op01001" -> "op01-001")
            // Si es Letra+Número seguido de 3 o 4 números, inyectamos el guion
            if (preg_match('/^([a-zA-Z]+\d+)(\d{3,4})$/', $cleanTerm, $matches)) {
                $variations[] = $matches[1] . '-' . $matches[2];
            }

            // Quitamos duplicados por si acaso
            $variations = array_unique($variations);

            // 4. Búsqueda nativa de Laravel (Cero fallos SQL)
            $query->where(function($q) use ($variations) {
                foreach ($variations as $var) {
                    $q->orWhere('name', 'LIKE', '%' . $var . '%')
                      ->orWhere('card_number', 'LIKE', '%' . $var . '%')
                      ->orWhere('unique_id', 'LIKE', '%' . $var . '%');
                }
            });
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

        if ($request->search === 'p103') {
            return response()->json([
                '1_sql_real' => $query->toSql(),
                '2_parametros_inyectados' => $query->getBindings(),
                '3_prueba_directa_bd' => \App\Models\CardTemplate::where('card_number', 'LIKE', '%103%')->get(['id', 'name', 'card_number', 'unique_id', 'card_set_id'])
            ]);
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