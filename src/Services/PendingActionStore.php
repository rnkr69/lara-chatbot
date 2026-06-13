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
 * Persistence and state transitions of the pending actions for frontend
 * tools `confirm`/`manual` (E16 — ROADMAP §5/E16).
 *
 * `ChatService::onToolCall` uses it to create the row when the frontend
 * tool is not `Auto`; the `ConfirmActionController` to resolve it when the
 * user decides; the `chatbot:cleanup-actions` command to mark expired ones.
 *
 * The supported transitions are:
 *
 *     pending  → confirmed → executed   (accept=true with/without result)
 *     pending  → rejected               (accept=false; terminal)
 *     pending  → expired                (cleanup; terminal)
 *     confirmed → executed              (widget reports result after executing)
 *
 * Any other transition throws `InvalidPendingActionTransition` and the
 * controller translates it into 409 Conflict for the client.
 */
class PendingActionStore
{
    /**
     * Creates a pending action from a frontend tool invocation with
     * `confirmation != Auto`. Returns the `action_id` (uuid) that the
     * `frontend_action` SSE event emits and that the widget uses as a handle.
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
     * v1.1.3 (#16) — Creates a pending action for a frontend tool with
     * `confirmation=Auto`. The row is born as `Confirmed` (semantically: "the
     * LLM already considers the action done, we only wait for a POST-back if the
     * widget reports a failure"). When the widget POST-backs with a result
     * `{ok:false, ...}`, the controller transitions it to `Executed` and the
     * `SystemPromptBuilder` emits it as `[FAILED]` on the next turn.
     *
     * If the widget does not POST-back (happy path = primitive ok), the row
     * stays `Confirmed` forever; it does not appear in `## Pending actions`
     * (the builder filters out the positive ones) and there is no runtime cost.
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
     * Transitions `pending → confirmed`. Called by `ConfirmActionController`
     * when the user approves before the widget has executed yet.
     */
    public function markConfirmed(PendingAction $action): PendingAction
    {
        $this->assertTransitionFrom($action, [PendingActionStatus::Pending], PendingActionStatus::Confirmed);

        $action->update(['status' => PendingActionStatus::Confirmed]);

        return $action->refresh();
    }

    /**
     * Transitions `pending → rejected` (terminal). If it arrives after a
     * transition to `confirmed` (the "user approved but now regrets it"
     * case), the flow does not handle that rollback in v1: another turn
     * with the LLM has to be generated. Keeping `Pending` as the only origin
     * simplifies auditing.
     *
     * @param  array<string, mixed>|null  $result  optional payload with the
     *                                              reason for the rejection
     *                                              (unstructured, emitted by
     *                                              the widget if there is a
     *                                              "reason" UI).
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
     * Transitions to `executed` (terminal) — the widget executed the primitive
     * and reports the result. Accepted from `pending` (accept+result in a
     * single call) or from `confirmed` (follow-up step after a prior accept
     * without result).
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
     * Marks as `expired` the `pending` ones whose `expires_at < now()`. Returns
     * how many rows were affected, useful for the schedulable command's output.
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
                'PendingActionStore::create does not support ConfirmationLevel::Auto. '
                . '`Auto` frontend tools execute in the widget without a pending action.'
            ),
        };
    }

    protected function resolveExpiry(PendingActionConfirmation $confirmation): Carbon
    {
        $key  = 'chatbot.limits.pending_action_ttl.' . $confirmation->value;
        $ttl  = (int) config($key, $confirmation === PendingActionConfirmation::Confirm ? 600 : 86_400);
        $ttl  = max(30, $ttl); // 30 s floor so as not to produce already-expired pendings.

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
            'Cannot transition pending action %s from %s to %s.',
            $action->action_id,
            $action->status->value,
            $to->value,
        ));
    }
}
