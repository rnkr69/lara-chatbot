# Confirmation flow — `auto`, `confirm`, `manual`

*English · [Español](confirmation-flow.es.md)*

> Confirmation levels for **frontend tools** and their lifecycle in the
> `rnkr69/lara-chatbot` package. Backend tools in v1 only support `auto`.

## TL;DR

| Level       | Who decides?             | Persistence                | LLM result             |
|-------------|--------------------------|----------------------------|------------------------|
| `auto`      | Nobody — runs directly   | None                       | `queued + action_id`   |
| `confirm`   | User approves/rejects    | `chatbot_pending_actions`  | `awaiting_user`        |
| `manual`    | User marks as done       | `chatbot_pending_actions`  | `awaiting_user`        |

`confirm` is for actions that **the widget executes** (critical navigation,
signed download, destructive call). `manual` is for actions that
**the user performs outside the chatbot** (signing a physical document, making
a phone call, etc.) and then marks as done/not done.

## Configuration

```php
// config/chatbot.php
'limits' => [
    // ...
    'pending_action_ttl' => [
        'confirm' => 600,     //  10 min — quick approval.
        'manual'  => 86_400,  //  24 h  — real human action.
    ],
    'pending_actions_in_prompt' => 10, // Cap for the ## Pending actions section.
],
```

The `chatbot:cleanup-actions` command marks expired `pending` rows as `expired`.
Recommended schedule (host):

```php
// app/Console/Kernel.php
$schedule->command('chatbot:cleanup-actions')->everyFiveMinutes();
```

## Lifecycle — `confirm`

```
┌──────────┐                                                ┌───────────┐
│ FrontendTool ───── ChatService::onToolCall ────────▶ pending          │
│ confirm  │                                          (chatbot_pending_actions)
└──────────┘                                                └─────┬─────┘
                                                                  │
   widget receives `frontend_action`,                             │
   renders Accept/Reject banner                                   │
                                                                  ▼
                          ┌──────────────────┐         ┌──────────────────┐
            POST {accept:false}              POST {accept:true}
            /actions/{id}/confirm            /actions/{id}/confirm
                          │                                   │
                          ▼                                   ▼
                     rejected (terminal)              confirmed (intermediate)
                          │                                   │
                          │                                   │ widget executes
                          │                                   │ the primitive locally
                          │                                   │
                          │                            POST {accept:true, result:...}
                          │                                   │
                          │                                   ▼
                          │                            executed (terminal)
                          ▼                                   ▼
                            next turn → ## Pending actions

                                  The LLM reads:
                                  - [REJECTED] tool=confirm_dialog ...
                                  - [PENDING]  tool=...  (if still open)
                                  - [EXPIRED]  tool=...  (if the cron ran)
```

### Shortcut: `accept + result` in a single call

The widget can combine acceptance and execution in a single call
if the result is available immediately:

```http
POST /chatbot/actions/abc-123/confirm
Content-Type: application/json

{ "accept": true, "result": { "ok": true, "downloaded_bytes": 8421 } }
```

→ the row moves from `pending` directly to `executed`. The intermediate
`confirmed` step is skipped.

## Lifecycle — `manual`

Same endpoint, same body. The widget renders a banner with
"Mark as done" and "Mark as not done" buttons:

- "Mark as done"     → `POST {accept: true, result: {done: true}}` → `executed`.
- "Mark as not done" → `POST {accept: false}` → `rejected`.

There is no intermediate `confirmed` step: the user reports the final outcome
directly.

## Endpoint `POST /chatbot/actions/{action}/confirm`

| Field    | Type   | Notes                                                       |
|----------|--------|-------------------------------------------------------------|
| `accept` | bool   | required                                                    |
| `result` | array? | optional; if sent with `accept=true`, forces `executed`     |

Path param `{action}` is the `action_id` (UUID) that travels in the SSE
`frontend_action` event.

### Response codes

| Code | When                                                                    |
|------|-------------------------------------------------------------------------|
| 200  | Transition OK. `data` contains the updated `PendingActionResource`.     |
| 401  | No session (`auth` middleware).                                         |
| 404  | Unknown `action_id` **or** belongs to another user (404-not-403).       |
| 409  | Terminal or expired state. `pending_action` contains the frozen row.    |
| 422  | Malformed body (`accept` missing or not a bool).                        |

### Idempotency

Terminal states (`rejected`, `executed`, `expired`) **do not** transition;
a second call returns `409 Conflict`. The exception is the
`pending → confirmed → executed` transition for the two-step flow.

## Injection into the system prompt — `## Pending actions`

`SystemPromptBuilder` programmatically adds this section when there are
relevant rows in the conversation:

```text
## Pending actions
The following frontend actions you proposed are awaiting/were resolved by the user.
Use this to avoid re-proposing rejected/expired actions and to acknowledge pending ones to the user:
- [REJECTED] tool=confirm_dialog action_id=abc-123 args={"message":"Send the email?"}
- [PENDING]  tool=open_modal     action_id=def-456 args={"id":42}
- [EXPIRED]  tool=download_file  action_id=ghi-789 args={"filename":"report.pdf"}
```

Only states that provide useful information for the next turn are dumped:

- `pending`  → "waiting for the user's response";
- `rejected` → "the user said no";
- `expired`  → "time ran out, decide whether to retry".

`confirmed` and `executed` are omitted — they are positive outcomes whose
effect is already in the world (the primitive ran). The list is truncated to
`chatbot.limits.pending_actions_in_prompt` entries, ordered by id desc.

## v1 Limitations

- **Backend tools do NOT support `confirm`/`manual`**. Filtered with an
  actionable `Log::warning`. For a host that needs hard confirmation over a
  backend action: implement it as a frontend tool that triggers the backend
  upon confirmation.
- **A pending action does not block the next turn** — the user can keep
  conversing with the LLM while `pending` rows exist. The LLM learns the
  outcome by reading the `## Pending actions` section in the next turn's prompt.
- **Step-up auth (`reauth`)** remains in the v1.1 backlog.

## End-to-end example

### Turn 1

```
User:    Can you send the welcome email?
LLM:     [tool_call] confirm_dialog({message: "Send welcome email?"})
ChatService persists pending action UUID=abc.
Returns `awaiting_user` to the LLM.
LLM:     "I'll confirm once you accept."
```

Widget receives `frontend_action {tool: confirm_dialog, confirmation: confirm,
action_id: abc}` → renders Accept/Reject banner below the assistant message.

### User rejects

```
POST /chatbot/actions/abc/confirm  {accept: false, result: {reason: "Tomorrow"}}
```

Row moves to `rejected`. Banner is removed. Toast `Rejected: confirm_dialog`.

### Turn 2

```
User: What happened with the email?
LLM (reads system prompt with):
  ## Pending actions
  - [REJECTED] tool=confirm_dialog action_id=abc args={"message":"Send welcome email?"}

LLM: "You postponed it until tomorrow. Let me know when you want to send it."
```

## Reference tests

- **Backend** (Pest):
  - `tests/Feature/Services/PendingActionStoreTest.php` — store transitions.
  - `tests/Feature/Http/ConfirmActionControllerTest.php` — REST endpoint.
  - `tests/Feature/Console/CleanupActionsCommandTest.php` — `chatbot:cleanup-actions` command.
- **Frontend** (Vitest):
  - `tests/js/confirm.test.ts` — banner UI + `postConfirm` + `deriveConfirmUrl`.
  - `tests/js/widget.test.ts` (section "confirm/manual banner routing").
