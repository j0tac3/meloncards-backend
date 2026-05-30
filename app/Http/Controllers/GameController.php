<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Devuelve la lista de todos los juegos con sus regiones disponibles.
     * Ideal para pintar el menú principal de tu aplicación.
     */
    public function index()
    {
        $games = Game::with('regions')->get();
        
        return response()->json($games);
    }

    /**
     * Devuelve la información de un solo juego buscando por su "slug" (ej: one-piece).
     * Ideal para cuando el usuario entra a la sección de un juego específico.
     */
    public function show($slug)
    {
        $game = Game::with('regions')->where('slug', $slug)->firstOrFail();
        
        return response()->json($game);
    }
}