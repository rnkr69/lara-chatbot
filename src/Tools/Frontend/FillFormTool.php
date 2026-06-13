<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

/**
 * Fills (and optionally submits) a form on the page.
 *
 * `form_id` points to the `<form>` element (or a wrapper with `data-chatbot-form`
 * that the host tags). The widget assigns each `fields[].value` to the control
 * with the corresponding `name` and, if `submit=true`, triggers the native submit.
 *
 * Confirmation: `confirm` by default, because the typical case covers a submit
 * that triggers a backend action. Hosts that only use it to pre-fill
 * drafts (without submit) can override to `auto` or register their own
 * subclass. When `submit=false` confirmation is optional from a UX
 * standpoint, but we keep the conservative default to minimize surprises.
 *
 * E11/v1 note: `confirmation()` is per-tool, not per-call. By the E16
 * contract (which does not exist yet) the `chatbot_pending_actions` cascade
 * will be applied according to this flag — for v1 the widget receives the
 * flag in the `frontend_action` and respects the UX, but the storage of the
 * pending action is introduced by E16.
 */
class FillFormTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'fill_form';
    }

    public function description(): string
    {
        return 'Fill the values of a form on the page and optionally submit it. Use when the user asks to start a request, file a ticket, or pre-fill data the AI gathered earlier in the conversation. Provide `fields[]` with `{name, value}` entries matching the form\'s `name` attributes (or its `data-chatbot-field` alias). Preferred targeting: pass `selector` verbatim from `crud.form.selector` in the page context (e.g. `[bp-section="crud-operation-create"] form` on Backpack — works without view overrides). Alternative: `form_id` matches a `<form>` id or `[data-chatbot-form]` wrapper for hosts that tag forms explicitly. If both are provided, `selector` wins. If neither is provided, the widget auto-discovers the first plausible form on the page (`main form`, `form#crudTable`, `form.form`, then any `form`). `submit=true` triggers the form\'s native submit afterwards; `submit=false` only fills the values for the user to review.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'selector' => ['type' => 'string', 'description' => 'CSS selector for the target form (preferred when the page context provides `crud.form.selector`). Resolves to a `<form>` directly or to a wrapper whose first `<form>` descendant is used.'],
                'form_id'  => ['type' => 'string', 'description' => 'Alternative to `selector`: DOM id of the target form, or a host-tagged `[data-chatbot-form]` wrapper. Use when the host has tagged forms with stable ids. If both `selector` and `form_id` are passed, `selector` wins.'],
                'fields'   => [
                    'type'        => 'array',
                    'description' => 'List of `{name, value}` entries to assign to form controls. `name` matches the HTML `name` attribute or the friendly `data-chatbot-field` alias.',
                ],
                'submit'   => ['type' => 'boolean', 'description' => 'Whether to submit the form after filling. Default false.'],
            ],
            'required' => ['fields'],
        ];
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Confirm;
    }
}
