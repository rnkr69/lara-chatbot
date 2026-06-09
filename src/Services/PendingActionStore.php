<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionConfirmation;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

/**
 * Persistencia y transiciones de estado de los pending actions de frontend
 * tools `confirm`/`manual` (E16 — ROADMAP §5/E16).
 *
 * El `ChatService::onToolCall` lo usa para crear el row cuando la frontend
 * tool no es `Auto`; el `ConfirmActionController` para resolverlo cuando el
 * usuario decide; el comando `chatbot:cleanup-actions` para marcar expirados.
 *
 * Las transiciones admitidas son:
 *
 *     pending  → confirmed → executed   (accept=true sin/con result)
 *     pending  → rejected               (accept=false; terminal)
 *     pending  → expired                (cleanup; terminal)
 *     confirmed → executed              (widget reporta result tras ejecutar)
 *
 * Cualquier otra transición lanza `InvalidPendingActionTransition` y el
 * controller la traduce en 409 Conflict para el cliente.
 */
class PendingActionStore
{
    /**
     * Crea un pending action a partir de una invocación de frontend tool con
     * `confirmation != Auto`. Devuelve el `action_id` (uuid) que el evento
     * SSE `frontend_action` emite y que el widget usa como handle.
     *
     * @param  array<string, mixed>  $args
     */
    public function create(
        Conversation $conversation,
        string $tool,
        array $args,
        ConfirmationLevel $confirmation,
    ): PendingAction {
        $confirmationEnum = $this->mapConfirmation($confirmation);

        return PendingAction::create([
            'conversation_id' => $conversation->getKey(),
            'action_id'       => (string) Str::uuid(),
            'tool'            => $tool,
            'args'            => $args,
            'status'          => PendingActionStatus::Pending,
            'confirmation'    => $confirmationEnum,
            'result'          => null,
            'expires_at'      => $this->resolveExpiry($confirmationEnum),
        ]);
    }

    /**
     * v1.1.3 (#16) — Crea un pending action para una frontend tool con
     * `confirmation=Auto`. El row nace como `Confirmed` (semánticamente: "el
     * LLM ya dio la acción por hecha, sólo esperamos un POST-back si el
     * widget reporta fallo"). Cuando el widget hace POST-back con un result
     * `{ok:false, ...}`, el controller lo transiciona a `Executed` y el
     * `SystemPromptBuilder` lo emite como `[FAILED]` en el siguiente turno.
     *
     * Si el widget no hace POST-back (happy path = primitive ok), el row se
     * queda en `Confirmed` para siempre; no aparece en `## Pending actions`
     * (el builder filtra los positivos) y no hay coste runtime.
     *
     * @param  array<string, mixed>  $args
     */
    public function createAutoConfirmed(
        Conversation $conversation,
        string $tool,
        array $args,
    ): PendingAction {
        return PendingAction::create([
            'conversation_id' => $conversation->getKey(),
            'action_id'       => (string) Str::uuid(),
            'tool'            => $tool,
            'args'            => $args,
            'status'          => PendingActionStatus::Confirmed,
            'confirmation'    => PendingActionConfirmation::Auto,
            'result'          => null,
            'expires_at'      => $this->resolveExpiry(PendingActionConfirmation::Auto),
        ]);
    }

    public function findByActionId(string $actionId): ?PendingAction
    {
        return PendingAction::query()->where('action_id', $actionId)->first();
    }

    /**
     * Transiciona `pending → confirmed`. Llamado por `ConfirmActionController`
     * cuando el usuario aprueba sin que el widget haya ejecutado todavía.
     */
    public function markConfirmed(PendingAction $action): PendingAction
    {
        $this->assertTransitionFrom($action, [PendingActionStatus::Pending], PendingActionStatus::Confirmed);

        $action->update(['status' => PendingActionStatus::Confirmed]);

        return $action->refresh();
    }

    /**
     * Transiciona `pending → rejected` (terminal). Si llega tras una
     * transición a `confirmed` (caso "el usuario aprobó pero ahora se
     * arrepiente"), el flujo no contempla esa marcha atrás en v1: hay que
     * generar otro turno con el LLM. Mantener `Pending` como única origen
     * simplifica la auditoría.
     *
     * @param  array<string, mixed>|null  $result  payload opcional con
     *                                              motivo del rechazo (no
     *                                              estructurado, lo emite
     *                                              el widget si hay UI de
     *                                              "razón").
     */
    public function markRejected(PendingAction $action, ?array $result = null): PendingAction
    {
        $this->assertTransitionFrom($action, [PendingActionStatus::Pending], PendingActionStatus::Rejected);

        $action->update([
            'status' => PendingActionStatus::Rejected,
            'result' => $result,
        ]);

        return $action->refresh();
    }

    /**
     * Transiciona a `executed` (terminal) — el widget ejecutó la primitiva
     * y reporta el resultado. Aceptado desde `pending` (accept+result en una
     * llamada) o desde `confirmed` (paso de seguimiento tras un accept previo
     * sin result).
     *
     * @param  array<string, mixed>|null  $result
     */
    public function markExecuted(PendingAction $action, ?array $result = null): PendingAction
    {
        $this->assertTransitionFrom(
            $action,
            [PendingActionStatus::Pending, PendingActionStatus::Confirmed],
            PendingActionStatus::Executed,
        );

        $action->update([
            'status' => PendingActionStatus::Executed,
            'result' => $result,
        ]);

        return $action->refresh();
    }

    /**
     * Marca como `expired` los `pending` cuyo `expires_at < now()`. Devuelve
     * cuántos rows se afectaron, útil para el output del comando schedulable.
     */
    public function expirePending(): int
    {
        return PendingAction::query()
            ->where('status', PendingActionStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => PendingActionStatus::Expired->value]);
    }

    protected function mapConfirmation(ConfirmationLevel $confirmation): PendingActionConfirmation
    {
        return match ($confirmation) {
            ConfirmationLevel::Confirm => PendingActionConfirmation::Confirm,
            ConfirmationLevel::Manual  => PendingActionConfirmation::Manual,
            ConfirmationLevel::Auto    => throw new \InvalidArgumentException(
                'PendingActionStore::create no admite ConfirmationLevel::Auto. '
                . 'Las frontend tools `Auto` se ejecutan en el widget sin pending action.'
            ),
        };
    }

    protected function resolveExpiry(PendingActionConfirmation $confirmation): Carbon
    {
        $key  = 'chatbot.limits.pending_action_ttl.' . $confirmation->value;
        $ttl  = (int) config($key, $confirmation === PendingActionConfirmation::Confirm ? 600 : 86_400);
        $ttl  = max(30, $ttl); // floor 30 s para no producir pendings ya expirados.

        return now()->addSeconds($ttl);
    }

    /**
     * @param  array<int, PendingActionStatus>  $allowedFrom
     */
    protected function assertTransitionFrom(
        PendingAction $action,
        array $allowedFrom,
        PendingActionStatus $to,
    ): void {
        if (in_array($action->status, $allowedFrom, true)) {
            return;
        }

        throw new InvalidPendingActionTransition(sprintf(
            'No se puede transicionar pending action %s de %s a %s.',
            $action->action_id,
            $action->status->value,
            $to->value,
        ));
    }
}
