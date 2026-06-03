<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        // 1. Empezamos la consulta usando la relación que ya creamos en User.php
        $query = $request->user()->wishlistedCards()
            ->with(['cardSet', 'prices']); // Cargamos la expansión y los precios

        // 2. Filtro opcional por juego (igual que en Mi Colección)
        if ($request->filled('game_id')) {
            $query->whereHas('cardSet', function($q) use ($request) {
                $q->where('game_id', $request->game_id);
            });
        }

        // 3. Obtenemos las cartas
        $wishlistedCards = $query->orderBy('wishlists.created_at', 'desc')->get();

        // 4. Transformamos los datos para que Angular los entienda fácilmente
        $formattedCards = $wishlistedCards->map(function ($card) {
            $set = $card->cardSet;
            $precio = $card->prices->first();

            return [
                'id' => $card->id,
                'name' => $card->name,
                'card_number' => $card->card_number,
                'set_name' => $set ? $set->name : 'Unknown',
                'set_total' => $set ? $set->total_cards : 0,
                'image_url' => $card->image_url,
                'market_price' => $precio ? (float) $precio->price : null,
                // Le decimos a Angular que esta carta está explícitamente en la wishlist
                'is_wishlisted' => true, 
            ];
        });

        return response()->json($formattedCards);
    }
    
    public function toggle(Request $request)
    {
        try {
            // 1. Validamos usando tu tabla real
            $request->validate([
                'card_id' => 'required|exists:card_templates,id'
            ]);

            $user = $request->user();

            // 2. Comprobamos que el usuario realmente tiene el método (para evitar fatal errors)
            if (!method_exists($user, 'wishlistedCards')) {
                throw new \Exception("El modelo User no tiene el método 'wishlistedCards'. Revisa App\Models\User.php");
            }

            // 3. Ejecutamos la lógica
            $resultado = $user->wishlistedCards()->toggle($request->card_id);

            // (He quitado la 'ñ' por seguridad)
            $isAdded = count($resultado['attached']) > 0;

            return response()->json([
                'status' => $isAdded ? 'added' : 'removed',
                'message' => $isAdded ? 'Añadida a la lista de deseos' : 'Eliminada de la lista de deseos'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Si falla la validación, devolvemos qué campo falló
            return response()->json([
                'error' => 'Error de validación',
                'detalles' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            // 🚨 SI ALGO EXPLOTA, LO CAPTURAMOS AQUÍ Y LO LEEMOS EN ANGULAR
            return response()->json([
                'error_real_php' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine()
            ], 500);
        }
    }
}