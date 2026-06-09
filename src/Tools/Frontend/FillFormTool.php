<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

/**
 * Rellena (y opcionalmente envía) un formulario de la página.
 *
 * `form_id` apunta al elemento `<form>` (o un wrapper con `data-chatbot-form`
 * que el host etiquete). El widget asigna cada `fields[].value` al control
 * con `name` correspondiente y, si `submit=true`, dispara el submit nativo.
 *
 * Confirmation: `confirm` por defecto, porque el caso típico cubre un submit
 * que dispara una acción de backend. Hosts que sólo lo usen para precargar
 * borradores (sin submit) pueden override a `auto` o registrar una subclase
 * propia. Cuando `submit=false` la confirmación es opcional desde la óptica
 * UX, pero mantenemos el default conservador para minimizar sorpresas.
 *
 * Nota E11/v1: el `confirmation()` es per-tool, no per-call. Por contrato
 * E16 (que aún no existe) la cascada `chatbot_pending_actions` se aplicará
 * según este flag — para v1 el widget recibe el flag en el `frontend_action`
 * y respeta la UX, pero el storage de la acción pendiente lo introduce E16.
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
