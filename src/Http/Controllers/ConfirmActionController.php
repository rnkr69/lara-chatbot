<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Rnkr69\LaraChatbot\Http\Requests\ConfirmActionRequest;
use Rnkr69\LaraChatbot\Http\Resources\PendingActionResource;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Services\InvalidPendingActionTransition;
use Rnkr69\LaraChatbot\Services\PendingActionStore;

/**
 * E16 — endpoint `POST /chatbot/actions/{action}/confirm`.
 *
 * Two-step semantics for `confirmation=confirm`:
 *
 *   1. Body `{accept: false}`            → `rejected` row (terminal).
 *      The widget executes nothing; the LLM will see it in
 *      `## Pending actions` on the next turn.
 *   2. Body `{accept: true}`             → `confirmed` row (intermediate).
 *      The widget receives 2xx and executes the primitive locally; when
 *      it finishes, it repeats the POST with `{accept: true, result: {...}}` to
 *      close the cycle in `executed` (terminal).
 *   3. Body `{accept: true, result: …}`  → `executed` row directly
 *      (the widget also accepts primitives that it executes before notifying
 *       the backend).
 *
 * For `confirmation=manual` the flow is simplified: the widget calls
 * `{accept: true, result: ...}` when the user marks the action as
 * done (or `{accept: false, result: {reason: '...'}}` if they mark it as
 * not-done). The backend does not distinguish the shape — the difference lives in the
 * widget's UI.
 *
 * Privacy: the endpoint applies the 404-not-403 doctrine of E10/D12. If the
 * `action_id` does not exist OR belongs to another conversation of the same or another
 * user, it returns 404 (not 403): it does not leak existence.
 *
 * Idempotency: touching a terminal row (`rejected`/`executed`/`expired`)
 * returns 409 Conflict with the cause.
 */
class ConfirmActionController extends Controller
{
    public function __construct(
        protected PendingActionStore $store,
    ) {}

    public function __invoke(ConfirmActionRequest $request, string $action): JsonResponse
    {
        $user = $request->user();

        // 404-not-403 (E10/D12): any action_id that does not belong to
        // a conversation of the user is indistinguishable from "does not exist".
        $pending = PendingAction::query()
            ->where('action_id', $action)
            ->forUser($user)
            ->first();

        if ($pending === null) {
            abort(404);
        }

        // Expiration pre-check: if the row is not marked as expired yet
        // but the TTL has passed, we mark it in this flow so
        // the response is coherent (no need to wait for the cron).
        if (
            $pending->status === PendingActionStatus::Pending
            && $pending->expires_at !== null
            && $pending->expires_at->isPast()
        ) {
            $pending->update(['status' => PendingActionStatus::Expired->value]);
            $pending->refresh();

            return $this->conflict('Pending action expired.', $pending);
        }

        $accept = (bool) $request->boolean('accept');
        $result = $this->normalizeResult($request->input('result'));

        try {
            if (! $accept) {
                $updated = $this->store->markRejected($pending, $result);

                return PendingActionResource::make($updated)
                    ->response()
                    ->setStatusCode(200);
            }

            // accept=true: if it carries result, the widget already executed (executed
            // terminal); if not, we only confirm (intermediate, the widget
            // will execute and call again with result).
            if ($result !== null) {
                $updated = $this->store->markExecuted($pending, $result);
            } else {
                $updated = $this->store->markConfirmed($pending);
            }

            return PendingActionResource::make($updated)
                ->response()
                ->setStatusCode(200);
        } catch (InvalidPendingActionTransition $e) {
            return $this->conflict($e->getMessage(), $pending->refresh());
        }
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>|null
     */
    protected function normalizeResult($raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return $raw === [] ? null : $raw;
    }

    protected function conflict(string $message, PendingAction $pending): JsonResponse
    {
        return response()->json([
            'message'        => $message,
            'pending_action' => PendingActionResource::make($pending)->toArray(request()),
        ], 409);
    }
}
