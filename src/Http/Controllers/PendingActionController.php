<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rnkr69\LaraChatbot\Http\Resources\PendingActionResource;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;

/**
 * v1.1 — listado de pending actions del usuario, usado por el widget al
 * rehidratarse para reconstruir banners de `confirmation=confirm|manual`
 * que quedaron sin resolver tras una navegación MPA.
 *
 * Filtros:
 *   - `status=pending` (default)  → sólo rows en estado `pending` y NO
 *      caducadas (TTL 10min/24h según `confirmation`).
 *   - `conversation_id=N`         → restringe al hilo activo. Sin él
 *      devuelve TODAS las pendings del usuario, lo cual rara vez interesa.
 *
 * Política privacy: `forUser($user)` ya descarta pendings ajenos
 * (404-no-403 al anidarse via `whereHas('conversation', ...)`).
 */
class PendingActionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PendingAction::query()
            ->forUser($user)
            ->orderBy('id', 'desc');

        $statusFilter = $request->query('status', 'pending');
        if ($statusFilter === 'pending') {
            $query->pending()
                ->where('expires_at', '>', now());
        } elseif (is_string($statusFilter) && $statusFilter !== '') {
            $statusEnum = PendingActionStatus::tryFrom($statusFilter);
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum->value);
            }
        }

        $convId = $request->query('conversation_id');
        if ($convId !== null && $convId !== '' && is_numeric($convId)) {
            $query->where('conversation_id', (int) $convId);
        }

        $perPage = (int) $request->query('per_page', 50);
        if ($perPage <= 0 || $perPage > 200) {
            $perPage = 50;
        }

        $rows = $query->limit($perPage)->get();

        return PendingActionResource::collection($rows)->response();
    }
}
