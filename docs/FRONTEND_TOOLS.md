# Frontend Tools — Implementation Guide

*English · [Español](FRONTEND_TOOLS.es.md)*

> This guide covers the `FrontendTool` contract (marker interface published in
> `src/Tools/Frontend/`) and the flows in which the LLM "invokes" tools whose
> real execution happens in the user's browser, not on the backend.
>
> Read [`docs/backend-tools.md`](backend-tools.md) first — frontend tools inherit the entire
> authorization cascade + JSON Schema + `ToolResult` mechanics and only
> differ in how the orchestrator (`ChatService`) emits the SSE event.

---

## 1. What is a frontend tool?

A `FrontendTool` is a tool that the LLM reasons about like any other
(with `name`, `description`, `parameters`, `permissions`, ...) but whose
material action is carried out by the widget in the browser:

- `navigate` does not open `Order::show` on the backend; it asks the widget
  to navigate to `/orders/123`.
- `show_toast` does not touch the DB; it asks the widget to display a toast
  on screen.
- `download_file` DOES touch the backend (signs a URL), but the download
  itself is triggered by the widget with `<a href download>`.

The orchestrator detects `instanceof FrontendTool` and, instead of emitting
`tool_call` + `tool_result`, emits `frontend_action` with everything the
widget needs.

---

## 2. Contract

```php
namespace Rnkr69\LaraChatbot\Tools\Contracts;

interface FrontendTool extends BackendTool {}
```

`FrontendTool` is a **marker interface** with no methods of its own — it
extends `BackendTool` so the host can register it in the same `ToolRegistry`
and so the validation/authorization cascade applies identically.

The natural way to implement a FE tool is to extend
`Rnkr69\LaraChatbot\Tools\BaseFrontendTool`, which in turn extends
`BaseBackendTool` (DRY) and provides a default `handle()` that returns
`ToolResult::success([])`. The catalog primitives and `DownloadFileTool`
inherit from here.

```php
namespace App\Chatbot\Tools;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

class OpenInvoiceModalTool extends BaseFrontendTool
{
    public function name(): string { return 'open_invoice_modal'; }
    public function description(): string { return 'Open a modal showing the invoice details for an id.'; }
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ['type' => 'integer'],
            ],
            'required' => ['invoice_id'],
        ];
    }
}
```

Without touching anything else, the orchestrator will emit

```
event: frontend_action
data: {"tool":"open_invoice_modal","args":{"invoice_id":42},"action_id":"<uuid>","confirmation":"auto"}
```

when the LLM invokes it.

---

## 3. The `BaseFrontendTool::handle()` shim

`handle()` returns `ToolResult::success([])` by default. `ChatService`:

1. Runs the `BaseBackendTool::execute()` cascade (validate args →
   permission → tenant → handle).
2. If OK, generates an `action_id` UUID and emits `frontend_action` with
   `{tool, args + result.data, action_id, confirmation}`.
3. Places `success(['status' => 'queued', 'action_id' => $uuid])` in the
   buffer returned to the LLM, so the step closes coherently.

If your FE tool **needs no backend logic**, do not override `handle()`. If
it does (`DownloadFileTool` signs a URL; a custom tool might resolve slugs
from an external service), override it and return the fields to merge into
`frontend_action.args`:

```php
public function handle(array $args, ToolContext $ctx): ToolResult
{
    $url = $this->signUrl($args['invoice_id']);

    return ToolResult::success([
        'pdf_url' => $url,
    ]);
}
```

The widget will receive `frontend_action.args.pdf_url` in addition to the
LLM's original args.

---

## 4. Confirmation levels

The `ConfirmationLevel` enum (`Auto|Confirm|Manual`) is defined by
`BackendTool` and inherited. The difference from backend tools:

- **Backend tools** only support `Auto` end-to-end. The orchestrator
  filters and warns via log.
- **Frontend tools** support all three levels — the `frontend_action`
  carries the flag so the widget decides whether to auto-execute, ask the
  user for confirmation, or mark as manual.

How to apply: override `confirmation()` and return the desired level.
The widget interprets:

| Level    | Widget behaviour |
|----------|-----------------|
| `auto`   | Executes immediately upon receiving the event. |
| `confirm`| Shows "Confirm X?" UI before executing. Persists the pending action. |
| `manual` | The user must trigger the action explicitly via a button in the chat. |

---

## 5. Core primitives catalogue

The 8 primitives live in `src/Tools/Frontend/` and are registered
automatically via `chatbot.tools.frontend_primitives`. Each exposes a
carefully written `description()` — the LLM chooses a tool based exclusively
on that text, so respect it or extend the primitive with wording better
suited to the host domain.

| Tool                     | `name()`             | Confirmation | Main args                                               |
|--------------------------|----------------------|--------------|---------------------------------------------------------|
| `NavigateTool`           | `navigate`           | auto         | `url` or `route` + `params`                             |
| `ToggleVisibilityTool`   | `toggle_visibility`  | auto         | `selector`, `action` (`show\|hide\|toggle`)             |
| `FillFormTool`           | `fill_form`          | confirm      | `fields[]` (required), `selector?`, `form_id?`, `submit?` |
| `ShowToastTool`          | `show_toast`         | auto         | `message`, `level?` (`info\|success\|warning\|error`)   |
| `OpenModalTool`          | `open_modal`         | auto         | `title`, `block` (typed block), `actions?[]`            |
| `RenderBlockTool`        | `render_block`       | auto         | `type`, `data`                                          |
| `InvokeHostActionTool`   | `invoke_host_action` | manual       | `action_name`, `args?`                                  |
| `DownloadFileTool`       | `download_file`      | auto         | `url_or_disk_path`, `filename?`, `mime?`, `expires_in?` |

### 5.1 `NavigateTool`

Takes the user to another screen. SPA uses the adapter registered via
`window.Chatbot.registerNavigator(...)`; MPA falls back to
`window.location.assign`.

```
User  : "open the orders list"
LLM   : navigate({route: 'orders.index'})
Widget: Inertia.visit('/orders')   // or location.assign
```

### 5.2 `ToggleVisibilityTool`

`show|hide|toggle` on one or more elements. Useful for progressive
disclosure flows ("show me the advanced filters").

### 5.3 `FillFormTool`

Fills a form and optionally submits it. **Default `confirm`** because the
typical case (`submit=true`) triggers a backend action; the host can
subclass to return `auto` if the real use case is always "just pre-fill
drafts".

Targeting:

1. **`selector`** (preferred) — CSS selector that resolves to the `<form>`
   directly or to a wrapper whose first descendant `<form>` is used.
   Emitted by the Backpack page context provider as
   `crud.form.selector = '[bp-section="crud-operation-create"] form'`,
   relying on the stable `bp-section` contract in Backpack 5/6/7.
2. **`form_id`** — alternative: id of a `<form>` or a wrapper with
   `data-chatbot-form`. Useful when the host labels its forms.
3. **Auto-discovery** — if nothing is passed, searches for the first
   plausible `<form>` (`main form`, `form#crudTable`, `form.form`, then any
   `form`). Drops a `console.warn` for diagnostics.

`fields[].name` matches both the HTML `name` attribute and the friendly
alias `data-chatbot-field` (the alias wins when both exist). If the LLM
calls with a non-existent name, the console warning lists both sets for
diagnostics.

```
User  : "fill the form with priority=express, risk=high"
LLM   : fill_form({
           selector: '[bp-section="crud-operation-create"] form',
           fields: [
             {name: 'priority', value: 'express'},
             {name: 'risk', value: 'high'},
           ],
         })
Widget: shows "Confirm form modification?"
```

For Backpack hosts, synchronisation with `crud.form.{selector, fields[]}`
from the page context lets the LLM resolve FK selects (`Mars → 2`) without
guessing — see [`integrations/backpack.md`](integrations/backpack.md).
For custom (non-Backpack) forms, publish the schema with the `@chatbotForm`
directive — see [`integrations/custom-forms.md`](integrations/custom-forms.md).

### 5.4 `ShowToastTool`

Ephemeral notification. Do NOT use it for questions — toasts auto-close;
use the chat or a modal to ask the user something.

### 5.5 `OpenModalTool`

Modal overlay with a typed block inside and optional action buttons.
If the `actions[]` include destructive tools, consider subclassing to
return `confirm`.

```
LLM   : open_modal({
           title: 'Confirm deletion',
           block: {type: 'card', data: {summary: '3 orders archived'}},
           actions: [
             {label: 'Delete', tool: 'archive_orders', args: {ids: [1,2,3]}},
             {label: 'Cancel'},
           ],
         })
```

### 5.6 `RenderBlockTool`

Inserts a block into the chat thread (not an overlay). For rich inline
responses. See [`block-renderers.md`](block-renderers.md) for the concrete renderers.

### 5.7 `InvokeHostActionTool`

Escape hatch. The host registers JS actions with
`window.Chatbot.registerAction('refreshGrid', () => {...})`. Default
`manual` by conservative contract.

### 5.8 `DownloadFileTool`

Generates a signed URL with expiry for downloading files from the host.
**Exception to the shim pattern**: its `handle()` executes backend logic.

#### Configuration

```php
// config/chatbot.php
'tools' => [
    'download_file' => [
        'allowed_disks'  => ['s3-invoices', 'r2-attachments'],
        'max_expires_in' => 3600,
    ],
],
```

Empty `allowed_disks` = no disk allowed (fail-secure default).

#### Ownership

Subclass and override `assertCanDownload()` for domain rules:

```php
namespace App\Chatbot\Tools;

use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class HostDownloadFileTool extends DownloadFileTool
{
    protected function assertCanDownload(string $disk, string $path, ToolContext $ctx): ?ToolResult
    {
        // Only PDFs whose owner is the current user
        if (preg_match('#invoices/(\d+)\.pdf$#', $path, $m)) {
            $invoice = \App\Models\Invoice::find((int) $m[1]);
            if ($invoice === null || $invoice->user_id !== $ctx->user->getAuthIdentifier()) {
                return ToolResult::error('not_owner', 'You cannot download this invoice.');
            }
        }
        return null;
    }
}
```

And replace the default primitive in config:

```php
'frontend_primitives' => [
    // ...
    \App\Chatbot\Tools\HostDownloadFileTool::class, // instead of the default
],
```

#### Widget flow

```
LLM   : download_file({url_or_disk_path: 's3-invoices::2026/123.pdf', filename: 'invoice.pdf'})
Tool  : Storage::disk('s3-invoices')->temporaryUrl(...)
SSE   : event: frontend_action
        data: {tool, args: {url_or_disk_path, filename, download_url, expires_at}, action_id, confirmation: 'auto'}
Widget: <a href="<download_url>" download="invoice.pdf">  → programmatic click
```

---

## 6. Events and persistence

The `Rnkr69\LaraChatbot\Events\ToolInvoked` event is also fired for
frontend tools — including when the cascade rejects them. Audit/PII
listeners receive the invocation just as they do for backend tools.

`tool_call` and `tool_result` SSE are omitted for frontend tools (their
informational shape is `frontend_action`). If the cascade rejects them,
`ChatService` emits `tool_result` with `ok=false` (informational) and
returns the error to the LLM.

---

## 7. Override recipes

| Case | Recipe |
|---|---|
| Change the `confirmation` of a primitive | Subclass + override `confirmation()`. Replace the entry in `chatbot.tools.frontend_primitives`. |
| Inject permissions into a primitive | Subclass + override `permissions()`. Core primitives return `[]` (public). |
| Adapt the wording to the host domain | Subclass + override `description()`. The LLM reads this text, not the package's. |
| Apply tenant scope | Subclass + override `tenantScope(): bool` → true + bind a `TenantResolver`. Note: the cascade applies this before the shim. |
| FE tool with backend logic | Override `handle()` and return `ToolResult::success([fields to merge])`. ChatService injects them into `frontend_action.args`. |
