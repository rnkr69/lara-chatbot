<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Llm;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * Construye el system prompt final que se envía al LLM.
 *
 * El prompt resultante es la concatenación de:
 *   1. La vista base (publishable) renderizada con $user, $pageContext,
 *      $tools, $locale y $addendum.
 *   2. La sección `## Current page` con el page_context sanitizado (E14).
 *      Se emite **programáticamente desde el builder**, NO desde la vista
 *      Blade, para que el contrato del paquete (qué ve el LLM cuando hay
 *      page_context) sobreviva a un override de la vista por el host.
 *   3. Una instrucción de idioma derivada del locale, emitida aquí (no en
 *      la vista) para que el host pueda customizar la base sin romper el
 *      contrato i18n del paquete.
 *
 * El "addendum" es el gap cross-host de E05: el host
 * declara `chatbot.system_prompt.addendum_view`, el builder lo renderiza
 * por separado y lo pasa como variable a la vista base; la vista base lo
 * incluye donde estime (la stub que se publica lo coloca al final).
 */
class SystemPromptBuilder
{
    /**
     * Mapa BCP47/ISO639 → nombre humano usado en la instrucción de idioma.
     * El host puede ampliarlo publicando un override de esta clase si lo
     * necesita (en v1 cubrimos los idiomas más comunes en la UE).
     *
     * @var array<string, string>
     */
    protected const LOCALE_NAMES = [
        'en' => 'English',
        'es' => 'Spanish',
        'ca' => 'Catalan',
        'pt' => 'Portuguese',
        'fr' => 'French',
        'it' => 'Italian',
        'de' => 'German',
        'nl' => 'Dutch',
        'gl' => 'Galician',
        'eu' => 'Basque',
    ];

    public function __construct(protected ViewFactory $views) {}

    /**
     * @param  array{
     *     user?: ?Authenticatable,
     *     pageContext?: array<string, mixed>,
     *     tools?: array<int, BackendTool>,
     *     locale?: ?string,
     *     conversation?: ?Conversation
     * }  $context
     */
    public function build(array $context = []): string
    {
        $context = $this->normalizeContext($context);
        $context['addendum'] = $this->renderAddendum($context);

        $base = $this->renderBase($context);

        $pageSection         = $this->pageContextSection($context['pageContext']);
        $decisionStrategy    = $this->decisionStrategySection();
        $pendingSection      = $this->pendingActionsSection($context['conversation']);
        $currentDateTime     = $this->currentDateTimeSection();
        $localeInstruction   = $this->localeInstruction($context['locale']);

        $parts = array_filter(
            [trim($base), $pageSection, $decisionStrategy, $pendingSection, $currentDateTime, $localeInstruction],
            static fn (string $part): bool => $part !== ''
        );

        return implode("\n\n", $parts);
    }

    /**
     * Construye el system prompt en dos bloques (v1.1.1, finding #14.g).
     *
     * - `cacheable`: secciones inmutables a lo largo de la conversación
     *   (base con tools + decision strategy + locale). En Anthropic se
     *   marca con `cache_control: ephemeral` para aprovechar el cache de
     *   ~5 min (90% descuento en hits + 50% menos latencia).
     * - `dynamic`: secciones que cambian turn a turn (page context +
     *   pending actions). Nunca cacheable.
     *
     * Las dos partes concatenadas con "\n\n" reproducen exactamente lo que
     * `build()` devuelve — la división es transparente para el LLM.
     *
     * @param  array{
     *     user?: ?Authenticatable,
     *     pageContext?: array<string, mixed>,
     *     tools?: array<int, BackendTool>,
     *     locale?: ?string,
     *     conversation?: ?Conversation
     * }  $context
     * @return array{cacheable: string, dynamic: string}
     */
    public function buildSplit(array $context = []): array
    {
        $context = $this->normalizeContext($context);
        $context['addendum'] = $this->renderAddendum($context);

        $base              = trim($this->renderBase($context));
        $decisionStrategy  = $this->decisionStrategySection();
        $localeInstruction = $this->localeInstruction($context['locale']);

        $cacheableParts = array_filter(
            [$base, $decisionStrategy, $localeInstruction],
            static fn (string $p): bool => $p !== '',
        );

        $pageSection     = $this->pageContextSection($context['pageContext']);
        $pendingSection  = $this->pendingActionsSection($context['conversation']);
        $currentDateTime = $this->currentDateTimeSection();

        $dynamicParts = array_filter(
            [$pageSection, $pendingSection, $currentDateTime],
            static fn (string $p): bool => $p !== '',
        );

        return [
            'cacheable' => implode("\n\n", $cacheableParts),
            'dynamic'   => implode("\n\n", $dynamicParts),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     user: ?Authenticatable,
     *     pageContext: array<string, mixed>,
     *     tools: array<int, BackendTool>,
     *     locale: ?string,
     *     conversation: ?Conversation,
     *     addendum: ?string
     * }
     */
    protected function normalizeContext(array $context): array
    {
        $conversation = $context['conversation'] ?? null;

        return [
            'user'         => $context['user'] ?? null,
            'pageContext'  => $context['pageContext'] ?? [],
            'tools'        => $context['tools'] ?? [],
            'locale'       => $context['locale'] ?? null,
            'conversation' => $conversation instanceof Conversation ? $conversation : null,
            'addendum'     => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function renderBase(array $context): string
    {
        $view = config('chatbot.system_prompt.view', 'chatbot::system_prompt');

        if (! is_string($view) || $view === '' || ! $this->views->exists($view)) {
            return 'You are a helpful assistant integrated into a Laravel application. Respond clearly and concisely.';
        }

        return $this->views->make($view, $context)->render();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function renderAddendum(array $context): ?string
    {
        $view = config('chatbot.system_prompt.addendum_view');

        if (! is_string($view) || $view === '' || ! $this->views->exists($view)) {
            return null;
        }

        $rendered = trim($this->views->make($view, $context)->render());

        return $rendered === '' ? null : $rendered;
    }

    /**
     * Sección `## Current page` que el builder añade programáticamente al
     * system prompt cuando hay page_context. Se emite aquí y NO en la vista
     * base (E14): la vista es publishable y un override del host podría
     * eliminarla sin querer; el contrato del paquete (el LLM siempre recibe
     * el page_context cuando llega) debe ser estable.
     *
     * @param  array<string, mixed>  $pageContext
     */
    protected function pageContextSection(array $pageContext): string
    {
        if ($pageContext === []) {
            return '';
        }

        $json = json_encode(
            $pageContext,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($json)) {
            return '';
        }

        return "## Current page\n"
            . "The user is currently looking at the following page (host-declared, sanitized):\n"
            . "```json\n"
            . $json . "\n"
            . "```";
    }

    /**
     * Sección `## Pending actions` (E16). El builder lista los pending
     * actions de la conversación cuyo estado importa para el siguiente turno
     * del LLM:
     *
     *  - `pending`  → "te queda pendiente decidir el usuario";
     *  - `rejected` → "el usuario te dijo NO en este turno o uno reciente";
     *  - `expired`  → "se te acabó el tiempo de espera; reintenta o sigue";
     *  - `executed` *sólo si* `result.ok === false` (v1.1.3 #16) → "ejecuté
     *    la primitiva en el widget pero falló". Le permite al LLM corregir
     *    el rumbo en su siguiente turno (ej. fall back a `navigate({url:?})`
     *    cuando un `fill_form` no encontró el form).
     *
     * `confirmed` y los `executed` exitosos se omiten: los efectos positivos
     * ya están en el mundo, mencionarlos sólo añade ruido al prompt.
     *
     * El listado se ordena por `id desc` y se trunca a
     * `chatbot.limits.pending_actions_in_prompt` (default 10) para no inflar
     * el system prompt cuando una conversación acumule muchas iteraciones.
     */
    protected function pendingActionsSection(?Conversation $conversation): string
    {
        if ($conversation === null || ! $conversation->exists) {
            return '';
        }

        $limit = (int) config('chatbot.limits.pending_actions_in_prompt', 10);
        if ($limit < 1) {
            $limit = 10;
        }

        $relevantStatuses = [
            PendingActionStatus::Pending->value,
            PendingActionStatus::Rejected->value,
            PendingActionStatus::Expired->value,
            PendingActionStatus::Executed->value, // v1.1.3 #16 — filtered below.
        ];

        $rows = PendingAction::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('status', $relevantStatuses)
            ->orderByDesc('id')
            ->limit($limit * 2) // headroom: we may discard executed-ok rows in PHP.
            ->get();

        $filtered = [];
        foreach ($rows as $row) {
            if ($row->status === PendingActionStatus::Executed
                && ! $this->isFailedExecutedRow($row)
            ) {
                // Successful execution — no need to remind the LLM.
                continue;
            }
            $filtered[] = $row;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        if ($filtered === []) {
            return '';
        }

        $lines = [];

        foreach ($filtered as $row) {
            $argsJson = json_encode(
                $row->args,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            if (! is_string($argsJson)) {
                $argsJson = '{}';
            }

            // v1.1.3 #16 — failed-executed rows render as `[FAILED]` and
            // include a compact result line so the LLM can see WHY it
            // failed (error code, message, e.g. available_forms list).
            if ($row->status === PendingActionStatus::Executed) {
                $resultJson = json_encode(
                    $row->result,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                if (! is_string($resultJson)) {
                    $resultJson = '{}';
                }
                $lines[] = sprintf(
                    '- [FAILED] tool=%s action_id=%s args=%s result=%s',
                    $row->tool,
                    $row->action_id,
                    $argsJson,
                    $resultJson,
                );
                continue;
            }

            $statusLabel = strtoupper($row->status->value);

            $lines[] = sprintf(
                '- [%s] tool=%s action_id=%s args=%s',
                $statusLabel,
                $row->tool,
                $row->action_id,
                $argsJson,
            );
        }

        return "## Pending actions\n"
            . "The following frontend actions you proposed are awaiting/were resolved by the user, "
            . "or were executed in the widget and reported a failure. "
            . "Use this to avoid re-proposing rejected/expired actions, to acknowledge pending ones, "
            . "and to RECOVER from FAILED ones (e.g. when fill_form couldn't find a form, fall back to "
            . "navigate({url: '?filter=...'}) for Backpack lists):\n"
            . implode("\n", $lines);
    }

    /**
     * v1.1.3 (#16) — Whether an `Executed` row reported a primitive failure
     * (`result.ok === false`). Successful executions are dropped from the
     * prompt; only failures are surfaced so the LLM can recover.
     */
    protected function isFailedExecutedRow(PendingAction $row): bool
    {
        $result = $row->result;
        if (! is_array($result)) {
            return false;
        }
        if (! array_key_exists('ok', $result)) {
            return false;
        }

        return $result['ok'] === false;
    }

    /**
     * Sección `## Current date/time` (v1.1.3, finding #23). Ancla temporal
     * para el LLM: sin esto el modelo tiende a alucinar fechas relativas
     * desde su training cutoff (e.g. el usuario pide "en 7 días" en 2026
     * pero el modelo emite fechas de 2025).
     *
     * Se emite en el segmento DINÁMICO (no cacheable): `now()` cambia turn
     * a turn y entraría en conflicto con el cache ephemeral de Anthropic.
     */
    protected function currentDateTimeSection(): string
    {
        $now = now();

        return "## Current date/time\n"
            . $now->toIso8601String() . ' (' . $now->format('l, F j, Y') . ")\n\n"
            . 'When you compute relative dates from user input ("mañana", "in 7 days", '
            . '"next month"), derive them from this timestamp, not from your training cutoff.';
    }

    /**
     * Sección "Page context — decision strategy" (v1.1.1, finding #12.a).
     *
     * Enseña al LLM a aprovechar la página actual: cuando el usuario está
     * en un grid Backpack y pide filtrar la misma entidad, mejor modificar
     * el grid existente (page-bound action) que duplicar la tabla en el
     * chat. Reglas transversales a cualquier host con page_context.
     *
     * Configurable: `chatbot.system_prompt.decision_strategy` puede ser
     * `false` para deshabilitarla, o el nombre de una vista Blade que el
     * host publique para usar su propia variante. Default: `true` (emite
     * las reglas estándar del package).
     *
     * Se devuelve cadena vacía cuando está deshabilitada o cuando la vista
     * declarada no existe.
     */
    protected function decisionStrategySection(): string
    {
        $setting = config('chatbot.system_prompt.decision_strategy', true);

        if ($setting === false || $setting === null) {
            return '';
        }

        if (is_string($setting) && $setting !== '' && $this->views->exists($setting)) {
            // Host-owned variant: emit only what the host wrote. The package
            // assumes the host knows their tools and intent layout — we don't
            // append v2.2 dashboard hints here.
            return trim($this->views->make($setting)->render());
        }

        $hints = $this->dashboardToolsHintsSection();

        return $hints === ''
            ? self::DEFAULT_DECISION_STRATEGY
            : self::DEFAULT_DECISION_STRATEGY . "\n\n" . $hints;
    }

    /**
     * v2.2 — Bullets que mapean intents conversacionales a las nuevas backend
     * tools del dashboard. Cada bullet aparece sólo si el toggle por-tool
     * está activo (`chatbot.tools.{name}.enabled`, default `true`); un host
     * que desactive `delete_dashboard` (por ejemplo) verá la sección sin esa
     * línea, evitando que el LLM la "sugiera" al usuario.
     *
     * Se omite enteramente cuando los 5 toggles están en `false` o cuando la
     * sección de decision_strategy está deshabilitada / customizada.
     */
    protected function dashboardToolsHintsSection(): string
    {
        $bullets = [];

        if ((bool) config('chatbot.tools.add_to_dashboard.enabled', true)) {
            $bullets[] = '- "add X to my dashboard" / "pin Y" / "save these KPIs to my panel" → `add_to_dashboard` (resolves the source tool, runs it, pins the resulting block). No widget needs to be visible in chat first.';
        }
        if ((bool) config('chatbot.tools.edit_widget.enabled', true)) {
            $bullets[] = '- "move / resize / rename a widget" / "change the refresh policy" → `edit_widget` (resolve `widget_id` from `page_context.dashboard.widgets`).';
        }
        if ((bool) config('chatbot.tools.delete_widget.enabled', true)) {
            $bullets[] = '- "remove / delete / unpin a widget" → `delete_widget`. **Confirm verbally with the user before invoking** — v2.2 has no UI banner for backend confirmation tools.';
        }
        if ((bool) config('chatbot.tools.edit_dashboard.enabled', true)) {
            $bullets[] = '- "rename my dashboard" / "set as default" → `edit_dashboard` (slug from `page_context.dashboard.slug`).';
        }
        if ((bool) config('chatbot.tools.delete_dashboard.enabled', true)) {
            $bullets[] = '- "delete my dashboard" → `delete_dashboard`. **Confirm verbally before invoking** (same reason as `delete_widget`). Refuses to delete the user\'s only dashboard.';
        }

        if ($bullets === []) {
            return '';
        }

        return "### Personal Dashboard — conversational tools (v2.2)\n\n"
            . "When the user is on `/chatbot/dashboard` (or refers to their personal dashboard), match these intents to the package's backend tools instead of refusing or asking for an id:\n\n"
            . implode("\n", $bullets)
            . "\n\nThe `page_context.dashboard` (auto-injected on the dashboard page) carries the current slug, widgets, titles and ids. Use it to resolve targets without asking the user. If the user is NOT on the dashboard page, you can still invoke these tools, but you may have to look up ids first (e.g. via `list_dashboards` if the host exposes it) — and **for the delete tools, always confirm verbally first**.";
    }

    /**
     * Texto canónico de decision strategy emitido cuando
     * `chatbot.system_prompt.decision_strategy=true` (default). Las reglas
     * son transversales a cualquier host con page context — el contenido
     * está pensado para que el LLM pueda razonar sobre la sección
     * `## Current page` que precede inmediatamente.
     */
    public const DEFAULT_DECISION_STRATEGY = <<<'PROMPT'
## Page context — decision strategy

The "Current page" section above (when present) tells you where the user
is. Use it to pick *where* to deliver the result, not just *what* tool to
call.

### Listings (`crud.action = list`)
- User asks to filter / search the SAME entity → prefer modifying the
  current grid. **Backpack default list views do NOT expose a `<form>` for
  filters** — they render popover dropdowns wired by Ajax. Calling
  `fill_form({form_id: 'filtersForm'})` will silently fail. Instead:
  build the URL with the desired querystring and call
  `navigate({url: '/admin/{entity}?{filter}={value}&{filter2}=...'})`.
  Backpack reads the filter values from the request on the next render
  and applies them. The host may also register a `host action` (e.g.
  `applyFilters`) — when present, prefer `invoke_host_action`.
- Forms that DO expose a `<form>` tag (custom hosts, non-Backpack admins)
  can use `fill_form` followed by `invoke_host_action('refreshGrid')`.
- Don't re-render the table in chat unless the user asks for "in chat" /
  "here" / "as a table message".
- `crud.filters.available` lists the filters the grid supports, with options
  for FK / enum filters. Map user labels (e.g. "Mars") to filter values
  (e.g. `destination_planet_id=2`) using that array.
- **Filter NOT available in the grid**: if the user asks to filter by an
  attribute that is NOT in `crud.filters.available`:
  1. If a backend tool accepts that attribute as an argument (e.g.
     `list_*` with `destination_planet_id`, `status`, etc.), call it with
     the value and render the result in chat **labelled as a fallback**
     ("the grid is not filtering — this result comes from the backend
     directly so you can see it inline").
  2. If no tool exposes that argument, tell the user the grid does not
     expose that filter and propose alternatives (free-text search,
     another page, manual scroll).
- **Never invent, paraphrase or re-label rows.** The `route` / `label`
  field of each row in `crud.rows` is canonical. If the column reads
  `Earth → Mars` and the user filtered by "Marte", the row matches; if it
  reads `Proxima Centauri b → Mercury`, it does NOT match — discard the
  row silently. If NO row matches, report "no missions match" — never
  claim "1 found" with a row whose actual destination is different.
- Translate user-language names to canonical values before comparing (the
  user saying "Marte" matches `Mars`, "Tierra" matches `Earth`).
- User asks for a DIFFERENT entity than the one being listed → use the
  backend tool that returns the data and render in chat (the page won't
  display it correctly).
- User asks for a summary (counts, top-N small) → chat block is fine
  regardless of page.

### Detail views (`crud.action = show`)
- User asks to act on the visible record (edit, cancel, sign, delete) →
  use the dedicated tool, don't re-show the card in chat.
- User asks for related data (events, shipments, history) → can render
  in chat as a complement (the page shows the main record only).

### Forms (`crud.action ∈ {create, update, edit}`)
- The page context exposes `crud.form.{selector, fields}` with `name`,
  `label`, `type`, and `options` for selects/enums (FK selects also expose
  `options` enumerated server-side, or `options_truncated: true` when the
  set is too large — in that case use a `list_*` tool to resolve labels →
  ids before calling `fill_form`).
- Pass `crud.form.selector` verbatim as `fill_form({selector, fields, ...})`.
  Do NOT guess an id with `getElementById`: Backpack default forms have
  no `id`, only the `bp-section` wrapper that the selector targets.
- Prefer `fill_form` over a backend `create_*` / `update_*` tool when the
  form is on screen — it lets the user review before submit.
- Only fall back to a backend write tool if the form is hidden, the page
  navigated away mid-conversation, or the user explicitly asks to "just
  do it without the form".
- **`fill_form` accepts partial input.** Fill the fields you have resolved
  from the conversation and leave the missing `required` ones empty for
  the user to complete by hand — native HTML5 validation will mark them
  at Save time. Do NOT poll the user `required`-by-`required` before
  calling the primitive: that wastes a turn and applies write-tool
  semantics to what is really a UI primitive.
- Example (do this): user on `/admin/mission/create` says "crea una
  mission de Tierra a Marte mañana, prioridad express, hazmat sí" and
  the form has `required` fields `ship_id` and `eta` that the user did
  NOT mention. Correct: call `fill_form({selector: crud.form.selector,
  fields: {origin_planet_id: 1, destination_planet_id: 2, departure_at:
  '<tomorrow ISO>', priority: 'express', hazmat: true}})` immediately —
  the browser will mark `ship_id`/`eta` red at Save and the user will
  complete them. Incorrect: ask "¿qué ship?" turn-by-turn before
  filling anything.
- Contrast with backend `create_*` / `update_*` tools: there you MUST
  collect every `required` before invoking, because the server validates
  them and a failure costs another round-trip.

### Host actions (`invoke_host_action`)
- Hosts may register frontend "host actions" (e.g. `refreshGrid`,
  `exportCsv`, `printManifest`, `applyFilters`) that the bundle bridges
  via `invoke_host_action({action_name, ...args})`. The available action
  names are NOT enumerated in this prompt — they live in the host's JS
  glue. Treat `invoke_host_action` as a fallback tool whenever the user
  asks for a UI operation that no other tool obviously covers:
  - "refresca el grid" / "recarga la tabla" / "reload" →
    `invoke_host_action({action_name: 'refreshGrid'})`.
  - "exporta a CSV" / "descarga el listado" →
    `invoke_host_action({action_name: 'exportCsv', ...filters})`.
  - "imprime el albarán/manifest" →
    `invoke_host_action({action_name: 'printManifest', shipment_id: …})`.
- Do NOT say "no tengo capacidad de hacer eso" before trying
  `invoke_host_action` first when the request maps to a plausible
  registered name. The tool returns a structured `PrimitiveResult` — on
  failure (`ok:false, error:'unknown_tool'`) you can fall back and tell
  the user it isn't wired up. Trying and failing is one turn; refusing
  pre-emptively is a missed turn.

### Result size heuristic
- < 5 rows: chat block is fine even on a related listing page.
- 5–20 rows: prefer page-bound action if available; ask if ambiguous.
- > 20 rows: page-bound action is almost always better. Chat is a poor
  long-table viewer; long tables also bloat the conversation context.

### When ambiguous, ASK
If you're not sure whether the user wants chat-bound or page-bound, ask
once in one sentence ("Te lo aplico al listado actual o te lo pinto aquí
en el chat?"). Don't ask every turn; remember the preference for the
remaining turns of this conversation.
PROMPT;

    protected function localeInstruction(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return '';
        }

        $key = strtolower(substr($locale, 0, 2));
        $name = self::LOCALE_NAMES[$key] ?? $locale;

        return "Always respond in {$name} unless the user explicitly requests another language.";
    }
}
