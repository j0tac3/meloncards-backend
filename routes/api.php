<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardCatalogController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\GameController;
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


Route::get('/tareas/update-prices', function (Request $request) {
    // 1. SISTEMA DE SEGURIDAD: Comprobamos el token
    $secret = env('CRON_SECRET');
    if (!$secret || $request->query('token') !== $secret) {
        abort(403, 'Acceso denegado. Token inválido.');
    }

    // 2. Dar tiempo infinito a PHP (porque la descarga puede tardar)
    set_time_limit(0);

    try {
        // 3. Ejecutamos tu comando Artisan
        Artisan::call('tcg:update-prices');
        
        return response()->json([
            'status' => 'success',
            'message' => '¡Precios actualizados!',
            'output' => Artisan::output() // Te mostrará el log del comando
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

Route::get('/tareas/update-pokemon', function (Request $request) {
    // 1. Reutilizamos el mismo sistema de seguridad
    $secret = env('CRON_SECRET');
    if (!$secret || $request->query('token') !== $secret) {
        abort(403, 'Acceso denegado. Token inválido.');
    }

    // 2. Dar tiempo infinito
    set_time_limit(0);

    try {
        // 3. 🚀 AQUÍ PON TU COMANDO DE POKÉMON
        // Cambia 'tcg:update-pokemon-prices' por el nombre real de tu comando
        Artisan::call('tcg:update-pokemon-prices'); 
        
        return response()->json([
            'status' => 'success',
            'message' => '¡Base de datos de Pokémon actualizada!',
            'output' => Artisan::output()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

Route::get('/tareas/ejecutar', function (Request $request) {
    // 1. ESCUDO 1: El Token Secreto
    $secret = env('CRON_SECRET');
    if (!$secret || $request->query('token') !== $secret) {
        abort(403, 'Acceso denegado. Token inválido.');
    }

    // 2. Extraer el comando de la URL
    $comando = $request->query('comando');
    if (!$comando) {
        return response()->json(['error' => 'Falta el parámetro "comando" en la URL.'], 400);
    }

    // 3. ESCUDO 2: LA LISTA BLANCA (¡Seguridad vital!)
    // Solo los comandos exactos que escribas aquí podrán ser ejecutados desde la web.
    $comandosPermitidos = [
        'tcg:update-prices',
        'tcg:import-one-piece',
        'tcg:update-pokemon-prices',
        'db:seed' // Recuerda quitar este cuando ya tengas tus usuarios base
    ];

    if (!in_array($comando, $comandosPermitidos)) {
        abort(403, "El comando '{$comando}' no está autorizado en la lista blanca.");
    }

    // 4. Ejecución
    set_time_limit(0); // Tiempo ilimitado

    try {
        // Ejecutamos el comando (forzando si es necesario para producción)
        Artisan::call($comando, ['--force' => true]);
        
        return response()->json([
            'status' => 'success',
            'comando_ejecutado' => $comando,
            'output' => Artisan::output()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'comando_fallido' => $comando,
            'message' => $e->getMessage()
        ], 500);
    }
});