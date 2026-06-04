<?php

namespace App\Http\Controllers;

use App\Models\CardSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SetController extends Controller
{
    /**
     * Lista de sets filtrable por juego y región.
     * GET /api/sets
     */
    public function index(Request $request): JsonResponse
    {
        $region = $request->input('region', 'en');

        $sets = CardSet::query()
            ->where('total_cards', '>', 0)
            ->whereNotNull('family')
            ->where('region', $region)
            ->when($request->filled('game_id'), fn($q) =>
                $q->where('game_id', $request->game_id)
            )
            ->orderBy('release_date', 'desc')
            ->get()
            ->map(function ($set) {
                // Imágenes locales: añadir dominio completo para Angular
                if ($set->image_url && str_starts_with($set->image_url, '/storage')) {
                    $set->image_url = url($set->image_url);
                }
                return $set;
            });

        return response()->json($sets);
    }
}
