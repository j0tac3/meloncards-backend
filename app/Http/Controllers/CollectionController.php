<?php

namespace App\Http\Controllers;

use App\Models\UserCard;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    // Ver la colección del usuario logueado
   // Ver la colección del usuario logueado
    public function index(Request $request)
    {
        // 1. Empezamos la consulta base
        $query = $request->user()->userCards()
            ->with(['cardTemplate.cardSet', 'cardTemplate.prices', 'cardState']);

        // 🚀 NUEVO: Filtramos por juego si nos envían el game_id
        if ($request->filled('game_id')) {
            $query->whereHas('cardTemplate.cardSet', function($q) use ($request) {
                $q->where('game_id', $request->game_id);
            });
        }

        // 2. Ejecutamos la consulta ordenando por fecha
        $userCards = $query->orderBy('created_at', 'desc')->get();

        // 3. Formateamos la respuesta para Angular
        $formattedCollection = $userCards->map(function ($userCard) {
            $template = $userCard->cardTemplate;
            $set = $template->cardSet; 
            
            $precio = $template->prices->first();

            return [
                'collection_id' => $userCard->id,
                'state' => $userCard->cardState->name,
                'language' => $userCard->language,
                'is_foil' => $userCard->is_foil,
                'quantity' => $userCard->quantity,
                
                'id' => $template->id,
                'name' => $template->name,
                'set_name' => $set ? $set->name : 'Unknown',
                'set_total' => $set ? $set->total_cards : 0, 
                'image_url' => $template->image_url,
                
                'market_price' => $precio ? (float) $precio->price : null,
            ];
        });

        return response()->json($formattedCollection);
    }

    // MODIFICADO: Agrupar cartas idénticas (Upsert)
    public function store(Request $request)
    {
        $request->validate([
            'card_template_id' => 'required|exists:card_templates,id',
            'card_state_id' => 'required|exists:card_states,id',
            'language' => 'required|string',
            'is_foil' => 'required|boolean',
            'quantity' => 'integer|min:1'
        ]);

        // 1. Buscamos si el usuario ya tiene una fila EXACTAMENTE con estas características
        $existingCard = $request->user()->userCards()
            ->where('card_template_id', $request->card_template_id)
            ->where('card_state_id', $request->card_state_id)
            ->where('language', $request->language)
            ->where('is_foil', $request->is_foil)
            ->first();

        if ($existingCard) {
            // Si ya existe, simplemente le sumamos la nueva cantidad
            $existingCard->increment('quantity', $request->quantity ?? 1);
            $userCard = $existingCard->fresh(); // Recargamos los datos actualizados
        } else {
            // Si no existe, creamos la fila nueva
            $userCard = $request->user()->userCards()->create([
                'card_template_id' => $request->card_template_id,
                'card_state_id' => $request->card_state_id,
                'language' => $request->language,
                'is_foil' => $request->is_foil,
                'quantity' => $request->quantity ?? 1,
            ]);
        }

        return response()->json([
            'message' => 'Carta guardada',
            'data' => $userCard
        ]);
    }

    public function checkOwned(Request $request, $cardTemplateId)
    {
        // Buscamos las cartas que tiene el usuario, incluyendo la información de en qué ESTADO están
        $ownedCards = $request->user()->userCards()
            ->with('cardState') // Traemos el estado (Mint, NM...)
            ->where('card_template_id', $cardTemplateId)
            ->get();

        return response()->json($ownedCards);
    }
    
    // MODIFICADO: Restar 1 si hay varias, o eliminar si solo queda 1
    public function destroy(Request $request, $id)
    {
        $userCard = $request->user()->userCards()->findOrFail($id);

        // Verificamos que la carta pertenezca al usuario logueado por seguridad
        if ($userCard->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if ($userCard->quantity > 1) {
            // Si hay más de una copia, restamos 1
            $userCard->decrement('quantity');
            return response()->json(['action' => 'decremented']);
        } else {
            // Si es la última copia, borramos la fila
            $userCard->delete();
            return response()->json(['action' => 'deleted']);
        }
    }

    public function getOwnedCount(Request $request, $cardId)
    {
        // Sumamos la cantidad de copias que tiene el usuario de esta carta específica
        $count = $request->user()->userCards()
            ->where('card_template_id', $cardId)
            ->sum('quantity');

        return response()->json(['owned_copies' => (int)$count]);
    }
}