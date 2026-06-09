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
 * Sem ántica de dos pasos para `confirmation=confirm`:
 *
 *   1. Body `{accept: false}`            → row `rejected` (terminal).
 *      El widget no ejecuta nada; el LLM lo verá en
 *      `## Pending actions` el siguiente turno.
 *   2. Body `{accept: true}`             → row `confirmed` (intermedio).
 *      El widget recibe 2xx y ejecuta la primitiva localmente; cuando
 *      acaba, repite el POST con `{accept: true, result: {...}}` para
 *      cerrar el ciclo en `executed` (terminal).
 *   3. Body `{accept: true, result: …}`  → row `executed` directamente
 *      (el widget también acepta primitivas que ejecuta antes de notificar
 *       al backend).
 *
 * Para `confirmation=manual` el flujo se simplifica: el widget llama
 * `{accept: true, result: ...}` cuando el usuario marca la acción como
 * hecha (o `{accept: false, result: {reason: '...'}}` si la marca como
 * no-hecha). El backend no diferencia el shape — la diferencia vive en la
 * UI del widget.
 *
 * Privacidad: el endpoint aplica la doctrina 404-no-403 de E10/D12. Si el
 * `action_id` no existe O pertenece a otra conversación del mismo o de otro
 * usuario, devuelve 404 (no 403): no filtra existencia.
 *
 * Idempotencia: tocar un row terminal (`rejected`/`executed`/`expired`)
 * devuelve 409 Conflict con la causa.
 */
class ConfirmActionController extends Controller
{
    public function __construct(
        protected PendingActionStore $store,
    ) {}

    public function __invoke(ConfirmActionRequest $request, string $action): JsonResponse
    {
        $user = $request->user();

        // 404-no-403 (E10/D12): cualquier action_id que no pertenezca a
        // una conversación del usuario es indistinguible de "no existe".
        $pending = PendingAction::query()
            ->where('action_id', $action)
            ->forUser($user)
            ->first();

        if ($pending === null) {
            abort(404);
        }

        // Pre-check de expiración: si el row no está marcado todavía como
        // expirado pero el TTL ha pasado, lo marcamos en este flow para
        // que la respuesta sea coherente (no hace falta esperar al cron).
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

            // accept=true: si trae result, el widget ya ejecutó (executed
            // terminal); si no, sólo confirmamos (intermedio, el widget
            // ejecutará y volverá a llamar con result).
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
