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

    // NUEVO: Alternar el estado de Favorito
    public function toggleFavorite(Request $request, $id)
    {
        $userCard = $request->user()->userCards()->findOrFail($id);
        
        $userCard->is_favorite = !$userCard->is_favorite;
        $userCard->save();

        return response()->json([
            'is_favorite' => $userCard->is_favorite,
            'message' => $userCard->is_favorite ? 'Marcada como favorita' : 'Quitada de favoritas'
        ]);
    }
}
