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
 * Builds the final system prompt sent to the LLM.
 *
 * The resulting prompt is the concatenation of:
 *   1. The base (publishable) view rendered with $user, $pageContext,
 *      $tools, $locale and $addendum.
 *   2. The `## Current page` section with the sanitized page_context (E14).
 *      It is emitted **programmatically from the builder**, NOT from the
 *      Blade view, so that the package's contract (what the LLM sees when
 *      there is page_context) survives a view override by the host.
 *   3. A language instruction derived from the locale, emitted here (not in
 *      the view) so that the host can customize the base without breaking the
 *      package's i18n contract.
 *
 * The "addendum" is the E05 cross-host gap: the host
 * declares `chatbot.system_prompt.addendum_view`, the builder renders it
 * separately and passes it as a variable to the base view; the base view
 * includes it wherever it sees fit (the published stub places it at the end).
 */
class SystemPromptBuilder
{
    /**
     * BCP47/ISO639 → human-readable name map used in the language instruction.
     * The host can extend it by publishing an override of this class if
     * needed (in v1 we cover the most common languages in the EU).
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
     * Builds the system prompt in two blocks (v1.1.1, finding #14.g).
     *
     * - `cacheable`: sections that are immutable throughout the conversation
     *   (base with tools + decision strategy + locale). On Anthropic it is
     *   marked with `cache_control: ephemeral` to take advantage of the
     *   ~5 min cache (90% discount on hits + 50% less latency).
     * - `dynamic`: sections that change turn to turn (page context +
     *   pending actions). Never cacheable.
     *
     * The two parts concatenated with "\n\n" reproduce exactly what
     * `build()` returns — the split is transparent to the LLM.
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
     * `## Current page` section that the builder adds programmatically to the
     * system prompt when there is page_context. It is emitted here and NOT in
     * the base view (E14): the view is publishable and a host override could
     * remove it by accident; the package's contract (the LLM always receives
     * the page_context when it arrives) must be stable.
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
     * `## Pending actions` section (E16). The builder lists the conversation's
     * pending actions whose state matters for the LLM's next turn:
     *
     *  - `pending`  → "you are still waiting for the user to decide";
     *  - `rejected` → "the user told you NO this turn or a recent one";
     *  - `expired`  → "your wait time ran out; retry or move on";
     *  - `executed` *only if* `result.ok === false` (v1.1.3 #16) → "I ran
     *    the primitive in the widget but it failed". It lets the LLM correct
     *    course on its next turn (e.g. fall back to `navigate({url:?})`
     *    when a `fill_form` did not find the form).
     *
     * `confirmed` and successful `executed` ones are omitted: the positive
     * effects are already in the world, mentioning them only adds noise to the prompt.
     *
     * The listing is ordered by `id desc` and truncated to
     * `chatbot.limits.pending_actions_in_prompt` (default 10) so as not to inflate
     * the system prompt when a conversation accumulates many iterations.
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
     * `## Current date/time` section (v1.1.3, finding #23). Temporal anchor
     * for the LLM: without this the model tends to hallucinate relative dates
     * from its training cutoff (e.g. the user asks for "in 7 days" in 2026
     * but the model emits dates from 2025).
     *
     * It is emitted in the DYNAMIC segment (not cacheable): `now()` changes
     * turn to turn and would conflict with Anthropic's ephemeral cache.
     */
    protected function currentDateTimeSection(): string
    {
        $now = now();

        return "## Current date/time\n"
            . $now->toIso8601String() . ' (' . $now->format('l, F j, Y') . ")\n\n"
            . 'When you compute relative dates from user input ("tomorrow", "in 7 days", '
            . '"next month"), derive them from this timestamp, not from your training cutoff.';
    }

    /**
     * "Page context — decision strategy" section (v1.1.1, finding #12.a).
     *
     * Teaches the LLM to take advantage of the current page: when the user is
     * on a Backpack grid and asks to filter the same entity, it is better to
     * modify the existing grid (page-bound action) than to duplicate the table
     * in the chat. Rules that cut across any host with page_context.
     *
     * Configurable: `chatbot.system_prompt.decision_strategy` can be
     * `false` to disable it, or the name of a Blade view that the
     * host publishes to use its own variant. Default: `true` (emits
     * the package's standard rules).
     *
     * An empty string is returned when it is disabled or when the declared
     * view does not exist.
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
     * v2.2 — Bullets that map conversational intents to the dashboard's new
     * backend tools. Each bullet appears only if the per-tool toggle
     * is active (`chatbot.tools.{name}.enabled`, default `true`); a host
     * that disables `delete_dashboard` (for example) will see the section
     * without that line, preventing the LLM from "suggesting" it to the user.
     *
     * It is omitted entirely when all 5 toggles are `false` or when the
     * decision_strategy section is disabled / customized.
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
     * Canonical decision strategy text emitted when
     * `chatbot.system_prompt.decision_strategy=true` (default). The rules
     * cut across any host with page context — the content is designed so that
     * the LLM can reason about the `## Current page` section that immediately
     * precedes it.
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
- Example (do this): user on `/admin/mission/create` says "create a
  mission from Earth to Mars tomorrow, express priority, hazmat yes" and
  the form has `required` fields `ship_id` and `eta` that the user did
  NOT mention. Correct: call `fill_form({selector: crud.form.selector,
  fields: {origin_planet_id: 1, destination_planet_id: 2, departure_at:
  '<tomorrow ISO>', priority: 'express', hazmat: true}})` immediately —
  the browser will mark `ship_id`/`eta` red at Save and the user will
  complete them. Incorrect: ask "which ship?" turn-by-turn before
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
  - "refresh the grid" / "reload the table" / "reload" →
    `invoke_host_action({action_name: 'refreshGrid'})`.
  - "export to CSV" / "download the list" →
    `invoke_host_action({action_name: 'exportCsv', ...filters})`.
  - "print the delivery note/manifest" →
    `invoke_host_action({action_name: 'printManifest', shipment_id: …})`.
- Do NOT say "I can't do that" before trying
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
once in one sentence ("Should I apply this to the current listing or
show it here in the chat?"). Don't ask every turn; remember the preference for the
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
