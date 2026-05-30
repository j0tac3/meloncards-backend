<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardCatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\GameController;


// --- RUTAS PÚBLICAS ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Catálogo de cartas (Cualquiera puede buscar cartas)
Route::get('/cards', [CardCatalogController::class, 'index']);
Route::get('/cards/{id}', [CardCatalogController::class, 'show']);

// Rutas públicas para los juegos y sus regiones
Route::get('/games', [GameController::class, 'index']);
Route::get('/games/{slug}', [GameController::class, 'show']);

Route::get('/sets', function (Request $request) {
    $query = \App\Models\CardSet::select('id', 'name')->orderBy('name');
    
    // 🚀 NUEVO: Filtramos las expansiones por el juego seleccionado
    if ($request->filled('game_id')) {
        $query->where('game_id', $request->game_id);
    }
    
    return response()->json($query->get());
});
// Rutas Públicas...
Route::get('/card-states', function () {
    return response()->json(\App\Models\CardState::all());
});


// --- RUTAS PROTEGIDAS (Requieren Token Sanctum) ---
Route::middleware('auth:sanctum')->group(function () {
    
    // Ver datos del usuario
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Gestión de la Colección Física
    Route::get('/collection', [CollectionController::class, 'index']);
    Route::post('/collection', [CollectionController::class, 'store']);
    Route::get('/collection/check/{templateId}', [CollectionController::class, 'checkOwned']);
    Route::delete('/collection/{id}', [CollectionController::class, 'destroy']);
    Route::get('/collection/count/{cardId}', [CollectionController::class, 'getOwnedCount']);

    Route::get('/prices/{card_id}', [PriceController::class, 'show']);
    
});