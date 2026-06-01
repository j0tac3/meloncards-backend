<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardCatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\ChecklistController;
use Illuminate\Support\Facades\Artisan;


// --- RUTAS PÚBLICAS ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Catálogo de cartas (Cualquiera puede buscar cartas)
Route::get('/cards', [CardCatalogController::class, 'index']);
Route::get('/cards/{id}', [CardCatalogController::class, 'show']);

// Rutas públicas para los juegos y sus regiones
Route::get('/games', [GameController::class, 'index']);
Route::get('/games/{slug}', [GameController::class, 'show']);

// routes/api.php
// routes/api.php
// routes/api.php
// routes/api.php

Route::get('/sets', function (Request $request) {
    $query = \App\Models\CardSet::where('total_cards', '>', 0)
                                ->whereNotNull('family')
                                ->orderBy('release_date', 'desc');

    if ($request->filled('game_id')) {
        $query->where('game_id', $request->game_id);
    }
    
    $region = $request->input('region', 'en'); 
    $query->where('region', $region);
    
    // 🚀 NUEVO: Modificamos los resultados antes de enviarlos
    $sets = $query->get()->map(function ($set) {
        // Si la imagen es local (empieza por /storage), le ponemos el dominio completo de Laravel
        if ($set->image_url && str_starts_with($set->image_url, '/storage')) {
            $set->image_url = url($set->image_url);
        }
        return $set;
    });
    
    return response()->json($sets);
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
    Route::get('/sets/{code}/checklist/pdf', [ChecklistController::class, 'downloadPdf']);
