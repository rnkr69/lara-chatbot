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
 * v1.1 — listing of the user's pending actions, used by the widget on
 * rehydration to reconstruct `confirmation=confirm|manual` banners
 * that were left unresolved after an MPA navigation.
 *
 * Filters:
 *   - `status=pending` (default)  → only rows in `pending` state and NOT
 *      expired (TTL 10min/24h depending on `confirmation`).
 *   - `conversation_id=N`         → restricts to the active thread. Without it
 *      it returns ALL of the user's pendings, which is rarely of interest.
 *
 * Privacy policy: `forUser($user)` already discards foreign pendings
 * (404-not-403 when nested via `whereHas('conversation', ...)`).
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
