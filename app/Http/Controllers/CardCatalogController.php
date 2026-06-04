<?php

namespace App\Http\Controllers;

use App\Models\CardTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardCatalogController extends Controller
{
    /**
     * Catálogo paginado de cartas con búsqueda inteligente, filtros y estado de usuario.
     * GET /api/cards
     */
    public function index(Request $request): JsonResponse
    {
        $query = CardTemplate::with(['cardSet', 'prices'])
            // Si el usuario está autenticado, marcamos si tiene la carta en wishlist
            ->when(auth('sanctum')->check(), function ($q) {
                $q->withExists(['wishlistedByUsers as is_wishlisted' => function ($sub) {
                    $sub->where('wishlists.user_id', auth('sanctum')->id());
                }]);
            });

        // ── Filtro por nombre / código con variaciones inteligentes ────────────
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                foreach ($this->buildSearchVariations($request->search) as $term) {
                    $q->orWhere('name',        'LIKE', "%{$term}%")
                      ->orWhere('card_number', 'LIKE', "%{$term}%")
                      ->orWhere('unique_id',   'LIKE', "%{$term}%");
                }
            });
        }

        // ── Filtros opcionales ────────────────────────────────────────────────
        $query->when($request->filled('card_set_id'), fn($q) =>
            $q->where('card_set_id', $request->card_set_id)
        );

        $query->when($request->filled('game_id'), fn($q) =>
            $q->whereHas('cardSet', fn($s) => $s->where('game_id', $request->game_id))
        );

        // ── Paginación ────────────────────────────────────────────────────────
        $perPage = min((int) $request->input('per_page', 15), 100); // máximo 100
        $cards   = $query->paginate($perPage);

        // ── Cantidades poseídas en una sola query (optimización en lote) ──────
        $ownedQuantities = $this->getOwnedQuantities($request, $cards->pluck('id')->all());

        // ── Transformar cada carta ────────────────────────────────────────────
        $cards->getCollection()->transform(function ($card) use ($ownedQuantities) {
            $card->set_name     = $card->cardSet?->name        ?? 'Unknown';
            $card->set_total    = $card->cardSet?->total_cards ?? 0;
            $card->owned_copies = $ownedQuantities[$card->id]  ?? 0;
            $card->market_price = $card->prices->first()?->price
                ? (float) $card->prices->first()->price
                : null;

            // Limpiar relaciones que ya hemos "aplanado" para no inflar el JSON
            unset($card->cardSet, $card->prices);

            return $card;
        });

        return response()->json($cards);
    }

    /**
     * Detalle de una carta concreta.
     * GET /api/cards/{id}
     */
    public function show(int $id): JsonResponse
    {
        $card = CardTemplate::with(['cardSet', 'prices'])->findOrFail($id);

        return response()->json([
            'id'           => $card->id,
            'name'         => $card->name,
            'card_number'  => $card->card_number,
            'unique_id'    => $card->unique_id,
            'image_url'    => $card->image_url,
            'attributes'   => $card->attributes,
            'set_name'     => $card->cardSet?->name        ?? 'Unknown',
            'set_code'     => $card->cardSet?->code        ?? null,
            'set_total'    => $card->cardSet?->total_cards ?? 0,
            'market_price' => $card->prices->first()?->price
                ? (float) $card->prices->first()->price
                : null,
        ]);
    }

    // ─── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Construye variaciones del término de búsqueda para cubrir formatos
     * como "op01001" → "OP01-001" o "p103" → "p-103".
     */
    private function buildSearchVariations(string $input): array
    {
        $clean = str_replace(['-', ' ', '–'], '', $input);

        $variations = [$input, $clean];

        // "p103" → "p-103"
        if (preg_match('/^([a-zA-Z]+)(\d+)$/', $clean, $m)) {
            $variations[] = $m[1] . '-' . $m[2];
        }

        // "op01001" → "op01-001"
        if (preg_match('/^([a-zA-Z]+\d+)(\d{3,4})$/', $clean, $m)) {
            $variations[] = $m[1] . '-' . $m[2];
        }

        return array_unique($variations);
    }

    /**
     * Devuelve un mapa [card_template_id => total_quantity] para los IDs dados.
     * Una sola query para todos los IDs de la página.
     */
    private function getOwnedQuantities(Request $request, array $cardIds): array
    {
        $user = $request->user('sanctum');

        if (!$user || empty($cardIds)) return [];

        return $user->userCards()
            ->whereIn('card_template_id', $cardIds)
            ->select('card_template_id', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('card_template_id')
            ->pluck('total_quantity', 'card_template_id')
            ->toArray();
    }
}
