<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardCatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\SetController;

// ═══════════════════════════════════════════════════════════════════
// RUTAS PÚBLICAS
// ═══════════════════════════════════════════════════════════════════

// ── Auth ──────────────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// ── Catálogo (lectura pública, el auth es opcional dentro del controller) ──
Route::get('/cards',      [CardCatalogController::class, 'index']);
Route::get('/cards/{id}', [CardCatalogController::class, 'show']);

// ── Juegos y Sets ─────────────────────────────────────────────────
Route::get('/games',       [GameController::class, 'index']);
Route::get('/games/{slug}', [GameController::class, 'show']);
Route::get('/sets',        [SetController::class, 'index']);

// ── Extras públicos ───────────────────────────────────────────────
Route::get('/card-states', fn() => response()->json(\App\Models\CardState::all()));
Route::get('/sets/{code}/checklist/pdf', [ChecklistController::class, 'downloadPdf']);

// ═══════════════════════════════════════════════════════════════════
// RUTAS PROTEGIDAS (requieren token Sanctum)
// ═══════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // ── Usuario ───────────────────────────────────────────────────
    Route::get('/user', fn(Request $request) => $request->user());

    // ── Colección ─────────────────────────────────────────────────
    Route::prefix('collection')->group(function () {
        Route::get('/',                     [CollectionController::class, 'index']);
        Route::post('/',                    [CollectionController::class, 'store']);
        Route::get('/check/{templateId}',   [CollectionController::class, 'checkOwned']);
        Route::get('/count/{cardId}',       [CollectionController::class, 'getOwnedCount']);
        Route::patch('/{id}/quantity',      [CollectionController::class, 'updateQuantity']);
        Route::delete('/{id}',              [CollectionController::class, 'destroy']);
        Route::post('/{id}/favorite',       [CollectionController::class, 'toggleFavorite']);
        Route::get('/dashboard-sets',       [CollectionController::class, 'dashboardSets']);
        Route::get('/search',               [CollectionController::class, 'searchCards']);
        Route::get('/set/{setId}',          [CollectionController::class, 'setCards']);
    });

    // ── Precios ───────────────────────────────────────────────────
    Route::get('/prices/{card_id}', [PriceController::class, 'show']);

    // ── Wishlist ──────────────────────────────────────────────────
    Route::get('/wishlist',          [WishlistController::class, 'index']);
    Route::post('/wishlist/toggle',  [WishlistController::class, 'toggle']);
});
