<?php

namespace App\Http\Controllers;

use App\Models\UserCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectionController extends Controller
{
    /**
     * Colección paginada del usuario con valor total de la bóveda.
     * GET /api/collection
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->userCards()
            ->with(['cardTemplate.cardSet', 'cardTemplate.prices', 'cardState']);

        // Filtro por juego
        $query->when($request->filled('game_id'), fn($q) =>
            $q->whereHas('cardTemplate.cardSet', fn($s) =>
                $s->where('game_id', $request->game_id)
            )
        );

        // Filtro por set
        $query->when($request->filled('card_set_id'), fn($q) =>
            $q->whereHas('cardTemplate', fn($t) =>
                $t->where('card_set_id', $request->card_set_id)
            )
        );

        // Búsqueda por nombre
        $query->when($request->filled('search'), fn($q) =>
            $q->whereHas('cardTemplate', fn($t) =>
                $t->where('name', 'LIKE', '%' . $request->search . '%')
            )
        );

        // ── Valor total de la bóveda en UNA sola query agregada ───────────────
        // Calculamos en BD antes de paginar → es el total REAL, no solo la página actual.
        $vaultValue = $this->calculateVaultValue($request);

        // ── Paginación ────────────────────────────────────────────────────────
        $perPage   = min((int) $request->input('per_page', 20), 100);
        $userCards = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // ── Transformar cada entrada ──────────────────────────────────────────
        $userCards->getCollection()->transform(function ($userCard) {
            $template = $userCard->cardTemplate;
            $set      = $template->cardSet;
            $precio   = $template->prices->first();

            return [
                'collection_id' => $userCard->id,
                'state'         => $userCard->cardState->name,
                'language'      => $userCard->language,
                'is_foil'       => (bool) $userCard->is_foil,
                'quantity'      => (int) $userCard->quantity,

                'id'          => $template->id,
                'name'        => $template->name,
                'card_number' => $template->card_number,
                'unique_id'   => $template->unique_id,
                'set_name'    => $set?->name        ?? 'Unknown',
                'set_code'    => $set?->code        ?? null,
                'set_total'   => (int) ($set?->total_cards ?? 0),
                'image_url'   => $template->image_url,
                'is_favorite' => $userCard->is_favorite,

                'market_price' => $precio ? (float) $precio->price : null,
                // Valor de línea: precio × cantidad (útil para desglose en Angular)
                'line_value'   => $precio
                    ? round((float) $precio->price * $userCard->quantity, 2)
                    : null,
            ];
        });

        return response()->json([
            'vault_value' => $vaultValue,  // KPI principal: valor total estimado en EUR
            'currency'    => 'EUR',
            'collection'  => $userCards,   // datos paginados + metadata Laravel
        ]);
    }

    /**
     * Añade una carta a la colección (o suma cantidad si ya existe).
     * POST /api/collection
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'card_template_id' => 'required|exists:card_templates,id',
            'card_state_id'    => 'required|exists:card_states,id',
            'language'         => 'required|string|max:10',
            'is_foil'          => 'required|boolean',
            'quantity'         => 'integer|min:1|max:999',
        ]);

        $quantity = $validated['quantity'] ?? 1;

        $userCard = $request->user()->userCards()
            ->where('card_template_id', $validated['card_template_id'])
            ->where('card_state_id',    $validated['card_state_id'])
            ->where('language',         $validated['language'])
            ->where('is_foil',          $validated['is_foil'])
            ->first();

        if ($userCard) {
            $userCard->increment('quantity', $quantity);
            $userCard = $userCard->fresh();
            $action   = 'incremented';
        } else {
            $userCard = $request->user()->userCards()->create([
                ...$validated,
                'quantity' => $quantity,
            ]);
            $action = 'created';
        }

        return response()->json([
            'message' => 'Carta guardada',
            'action'  => $action,
            'data'    => $userCard,
        ], $action === 'created' ? 201 : 200);
    }

    /**
     * Comprueba si el usuario posee una carta y en qué estados.
     * GET /api/collection/check/{templateId}
     */
    public function checkOwned(Request $request, int $cardTemplateId): JsonResponse
    {
        $ownedCards = $request->user()->userCards()
            ->with('cardState')
            ->where('card_template_id', $cardTemplateId)
            ->get(['id', 'card_template_id', 'card_state_id', 'language', 'is_foil', 'quantity']);

        return response()->json($ownedCards);
    }

    /**
     * Resta 1 unidad o elimina si es la última copia.
     * DELETE /api/collection/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // findOrFail en la relación del usuario garantiza que solo puede
        // tocar sus propias cartas → la comprobación manual de user_id era redundante
        $userCard = $request->user()->userCards()->findOrFail($id);

        if ($userCard->quantity > 1) {
            $userCard->decrement('quantity');
            return response()->json([
                'action'    => 'decremented',
                'remaining' => $userCard->quantity - 1,
            ]);
        }

        $userCard->delete();
        return response()->json(['action' => 'deleted']);
    }

    /**
     * Total de copias que posee el usuario de una carta concreta.
     * GET /api/collection/count/{cardId}
     */
    public function getOwnedCount(Request $request, int $cardId): JsonResponse
    {
        $count = $request->user()->userCards()
            ->where('card_template_id', $cardId)
            ->sum('quantity');

        return response()->json(['owned_copies' => (int) $count]);
    }

    /**
     * Actualiza la cantidad directamente (edición inline en Angular).
     * PATCH /api/collection/{id}
     */
    public function updateQuantity(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:999',
        ]);

        $userCard = $request->user()->userCards()->findOrFail($id);
        $userCard->update(['quantity' => $validated['quantity']]);

        return response()->json(['action' => 'updated', 'data' => $userCard]);
    }

    // ─── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Calcula el valor total estimado de la bóveda en EUR en la BD.
     * Una sola query JOIN+SUM en lugar de iterar en PHP carta a carta.
     *
     * Fórmula: SUM(user_cards.quantity × card_prices.price)
     */
    private function calculateVaultValue(Request $request): float
    {
        $query = DB::table('user_cards')
            ->join('card_templates', 'card_templates.id', '=', 'user_cards.card_template_id')
            ->join('card_prices',    'card_prices.card_template_id', '=', 'card_templates.id')
            ->where('user_cards.user_id', $request->user()->id)
            ->where('card_prices.currency', 'EUR');

        if ($request->filled('game_id')) {
            $query->join('card_sets', 'card_sets.id', '=', 'card_templates.card_set_id')
                  ->where('card_sets.game_id', $request->game_id);
        }

        $total = $query->sum(DB::raw('user_cards.quantity * card_prices.price'));

        return round((float) $total, 2);
    }

    /**
     * Obtiene las expansiones (sets) del usuario paginadas para el modo "Escaparate".
     * GET /api/collection/dashboard-sets
     */
    public function dashboardSets(Request $request): \Illuminate\Http\JsonResponse
    {
        $gameId = $request->query('game_id');
        $user = $request->user();
        $perPage = 5;

        // 1. Obtenemos las expansiones y leemos directamente el 'total_cards' de tu tabla
        $paginatedSets = \DB::table('user_cards')
            ->join('card_templates', 'user_cards.card_template_id', '=', 'card_templates.id')
            ->join('card_sets', 'card_templates.card_set_id', '=', 'card_sets.id')
            ->where('user_cards.user_id', $user->id)
            ->where('card_sets.game_id', $gameId)
            ->select('card_sets.id as set_id', 'card_sets.name as set_name', 'card_sets.total_cards')
            ->groupBy('card_sets.id', 'card_sets.name', 'card_sets.total_cards')
            ->orderBy('card_sets.name')
            ->paginate($perPage);

        if ($paginatedSets->isEmpty()) {
            return response()->json(['data' => [], 'stats' => null, 'has_more_pages' => false]);
        }

        $setIds = $paginatedSets->pluck('set_id');

        // 2. Calculamos SOLO las cartas únicas que posee el usuario
        $ownedUniquePerSet = \DB::table('user_cards')
            ->join('card_templates', 'user_cards.card_template_id', '=', 'card_templates.id')
            ->join('card_prices', 'user_cards.card_template_id', '=', 'card_prices.card_template_id')
            ->where('user_cards.user_id', $user->id)
            ->whereIn('card_templates.card_set_id', $setIds)
            ->select('card_templates.card_set_id', \DB::raw('count(distinct card_templates.id) as owned'))
            ->groupBy('card_templates.card_set_id')
            ->pluck('owned', 'card_set_id');

        // 3. Montamos las Estanterías
        $dashboardRows = [];

        foreach ($paginatedSets as $set) {
            $recentCards = $user->userCards()
                ->with(['cardTemplate.cardSet', 'cardTemplate.prices', 'cardState'])
                ->whereHas('cardTemplate', function ($q) use ($set) {
                    $q->where('card_set_id', $set->set_id);
                })
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->map(function ($userCard) {
                    $template = $userCard->cardTemplate;
                    $precio   = $template->prices->first();

                    return [
                        'collection_id' => $userCard->id,
                        'id'            => $template->id,
                        'is_favorite'   => (bool) $userCard->is_favorite,
                        'quantity'      => (int) $userCard->quantity,
                        'state'         => $userCard->cardState?->name,
                        'name'          => $template->name,
                        'card_number'   => $template->card_number,
                        'unique_id'     => $template->unique_id,
                        'image_url'     => $template->image_url,
                        'set_name'      => $template->cardSet?->name ?? 'Desconocido',
                        'market_price'  => $precio ? (float) $precio->price : null,
                    ];
                });

            $dashboardRows[] = [
                'set_id' => $set->set_id,
                'set_name' => $set->set_name,
                'owned_unique' => $ownedUniquePerSet[$set->set_id] ?? 0,
                'total_in_set' => $set->total_cards ?? 0, // <-- Usamos tu columna directamente
                'recent_cards' => $recentCards
            ];
        }

        // 4. Calculamos los KPIs Globales (Solo en la página 1)
        $stats = null;
        if ($paginatedSets->currentPage() === 1) {
            $baseQuery = $user->userCards()->whereHas('cardTemplate.cardSet', function ($q) use ($gameId) {
                $q->where('game_id', $gameId);
            });

            $stats = [
                'physical' => (clone $baseQuery)->sum('quantity'),
                'unique' => (clone $baseQuery)->distinct('card_template_id')->count('card_template_id'),
                'foil' => (clone $baseQuery)->where('is_foil', true)->sum('quantity'),
                'vault' => (float) \DB::table('user_cards')
                        ->join('card_templates', 'card_templates.id', '=', 'user_cards.card_template_id')
                        ->join('card_sets',      'card_sets.id',      '=', 'card_templates.card_set_id')
                        ->join('card_prices',    'card_prices.card_template_id', '=', 'card_templates.id')
                        ->where('user_cards.user_id', $user->id)
                        ->where('card_sets.game_id', $gameId)
                        ->where('card_prices.currency', 'EUR')
                        ->sum(\DB::raw('user_cards.quantity * card_prices.price')),
            ];
        }

        return response()->json([
            'data' => $dashboardRows,
            'stats' => $stats,
            'current_page' => $paginatedSets->currentPage(),
            'last_page' => $paginatedSets->lastPage(),
            'has_more_pages' => $paginatedSets->hasMorePages()
        ]);
    }

    /**
     * Busca cartas específicas en toda la colección del usuario (Modo Cuadrícula).
     * GET /api/collection/search
     */
    public function searchCards(Request $request): \Illuminate\Http\JsonResponse
    {
        $gameId = $request->query('game_id');
        $searchTerm = $request->query('search');
        $perPage = 30;

        $query = $request->user()->userCards()
            ->with(['cardTemplate.cardSet', 'cardState'])
            ->whereHas('cardTemplate', function ($q) use ($gameId, $searchTerm) {
                
                // ✅ CORRECCIÓN 1: Filtramos por juego pasando a través de la tabla card_sets
                $q->whereHas('cardSet', function ($qSet) use ($gameId) {
                    $qSet->where('game_id', $gameId);
                });
                
                // ✅ CORRECCIÓN 2: Aplicamos la búsqueda de texto
                if (!empty($searchTerm)) {
                    $q->where(function($subQ) use ($searchTerm) {
                        $subQ->where('name', 'like', "%{$searchTerm}%")
                             ->orWhere('card_number', 'like', "%{$searchTerm}%");
                    });
                }
            })
            ->orderBy('created_at', 'desc');

        $paginatedCards = $query->paginate($perPage);

        $formattedCards = $paginatedCards->map(function ($userCard) {
            return [
                'collection_id' => $userCard->id,
                'is_favorite' => $userCard->is_favorite,
                'quantity' => $userCard->quantity,
                'state' => $userCard->cardState->name ?? null,
                'name' => $userCard->cardTemplate->name,
                'image_url' => $userCard->cardTemplate->image_url,
                'set_name' => $userCard->cardTemplate->cardSet->name ?? 'Desconocido',
                'market_price' => $userCard->cardTemplate->market_price ?? 0,
            ];
        });

        return response()->json([
            'data' => $formattedCards,
            'current_page' => $paginatedCards->currentPage(),
            'last_page' => $paginatedCards->lastPage(),
            'has_more_pages' => $paginatedCards->hasMorePages()
        ]);
    }

    /**
     * Obtiene todas las cartas de un set específico en la colección del usuario.
     * Permite búsqueda local por nombre o número dentro de ese set.
     * GET /api/collection/set/{setId}
     */
    public function setCards(Request $request, int $setId): \Illuminate\Http\JsonResponse
    {
        $setId = (int) $setId; 

        $user = $request->user();
        $searchTerm = $request->query('search');
        $perPage = 30; // 30 cartas por página para la cuadrícula masiva

        // 1. Query base para las cartas del usuario estrictamente en este set
        $query = $user->userCards()
            ->with(['cardTemplate.cardSet', 'cardState'])
            ->whereHas('cardTemplate', function ($q) use ($setId, $searchTerm) {
                // Filtramos por el set exacto
                $q->where('card_set_id', $setId);
                
                // Si el usuario usa el buscador de esta pantalla, filtramos
                if (!empty($searchTerm)) {
                    $q->where(function($subQ) use ($searchTerm) {
                        $subQ->where('name', 'like', "%{$searchTerm}%")
                             ->orWhere('card_number', 'like', "%{$searchTerm}%");
                    });
                }
            })
            ->orderBy('created_at', 'desc');

        $paginatedCards = $query->paginate($perPage);

        // 2. Formateamos las cartas igual que en el resto de la app
        $formattedCards = $paginatedCards->map(function ($userCard) {
            return [
                'collection_id' => $userCard->id,
                'is_favorite' => $userCard->is_favorite,
                'quantity' => $userCard->quantity,
                'state' => $userCard->cardState->name ?? null,
                'name' => $userCard->cardTemplate->name,
                'image_url' => $userCard->cardTemplate->image_url,
                'set_name' => $userCard->cardTemplate->cardSet->name ?? 'Desconocido',
                'market_price' => $userCard->cardTemplate->market_price ?? 0,
            ];
        });

        // 3. Extra: Si es la primera página, mandamos la info del Set para la cabecera
        $setInfo = null;
        if ($paginatedCards->currentPage() === 1) {
            $set = \DB::table('card_sets')->where('id', $setId)->first();
            
            $ownedUnique = \DB::table('user_cards')
                ->join('card_templates', 'user_cards.card_template_id', '=', 'card_templates.id')
                ->where('user_cards.user_id', $user->id)
                ->where('card_templates.card_set_id', $setId)
                ->distinct('card_templates.id')
                ->count('card_templates.id');

            if ($set) {
                $setInfo = [
                    'name' => $set->name,
                    'total_cards' => $set->total_cards,
                    'owned_unique' => $ownedUnique
                ];
            }
        }

        return response()->json([
            'data' => $formattedCards,
            'set_info' => $setInfo,
            'current_page' => $paginatedCards->currentPage(),
            'last_page' => $paginatedCards->lastPage(),
            'has_more_pages' => $paginatedCards->hasMorePages()
        ]);
    }

    /**
     * Alterna (toggle) el estado de favorito de una carta en la colección.
     * POST /api/collection/{id}/favorite
     */
    public function toggleFavorite(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        // 1. Obtenemos al usuario autenticado
        $user = $request->user();
        // 2. Buscamos la carta específica en su colección
        // (Usamos userCards() para asegurarnos de que la carta realmente le pertenece a él)
        $userCard = $user->userCards()->findOrFail($id);
        // 3. Invertimos el valor actual (si era true pasa a false y viceversa)
        $userCard->is_favorite = !$userCard->is_favorite;
        // 4. Guardamos los cambios en la base de datos
        $userCard->save();
        // 5. Devolvemos la respuesta exacta que espera tu Angular
        return response()->json([
            'is_favorite' => $userCard->is_favorite,
            'message' => $userCard->is_favorite ? 'Añadida a favoritos' : 'Eliminada de favoritos'
        ]);
    }
}
