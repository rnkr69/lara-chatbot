# Decision strategies — Page-aware prompting

*English · [Español](decision-strategies.es.md)*

> How the package teaches the LLM to leverage the user's current page
> instead of duplicating content inside the chat. Introduced in v1.1.1.

Pre-reading: [`page-context.md`](page-context.md) (how page context is sanitised)
+ [`FRONTEND_TOOLS.md`](FRONTEND_TOOLS.md) (catalogue of primitives the LLM can
use to act on the page).

---

## 1. Why this doc?

A natural conversation in an admin panel has two routes to deliver a result:

- **Chat-bound**: the LLM calls a backend tool, receives the data and
  renders it as a block (table, KPI, chart) INSIDE the chat.
- **Page-bound**: the LLM calls a frontend tool that modifies the UI the
  user already has on screen (filter the grid, open a drawer, fill a form).

For a task like *"show me the last 50 missions to Mars"* from
`/admin/mission` (Backpack list view), both routes work "technically",
but the experience is very different:

| Route | UX | Tokens | Persistence |
|---|---|---|---|
| Chat-bound (`list_my_missions` → `render_block` table) | Table duplicated below the native table the user already sees. Visual clutter. | ~3000 tokens out + stored in `chatbot_messages` and returned to context on the next turn. | The block is persisted and will reload on future visits to the conversation. |
| Page-bound (`fill_form(filtersForm)` + `refreshGrid`) | Filters the existing DataTable. Native UX. | ~50 tokens out. Data never enters the LLM context. | Reversible with a single user click. |

Without guidance, the LLM picks the first because it is the most obvious
from the perspective of "which tool to use to answer this". The package
appends a section to the system prompt that teaches it to prefer the
second whenever the page allows it.

---

## 2. The rules (what the LLM sees)

The "Page context — decision strategy" section is concatenated to the
system prompt after `## Current page`. Its canonical content:

```
### Listings (`crud.action = list`)
- User asks to filter / search the SAME entity → prefer modifying the
  current grid (fill_form on filtersForm + invoke_host_action('refreshGrid')).
- User asks for a DIFFERENT entity → use the backend tool that returns
  the data and render in chat.
- Summary (counts, top-N small) → chat block regardless of page.

### Detail views (`crud.action = show`)
- Act on the visible record → use the dedicated tool, don't re-show the card.
- Related data → can render in chat as complement.

### Forms (`crud.action ∈ {create, update, edit}`)
- Use crud.form schema + fill_form first.
- Fall back to backend write tool only when the form is hidden / navigated away.

### Result size heuristic
- < 5 rows: chat block is fine.
- 5–20 rows: page-bound preferred.
- > 20 rows: page-bound almost always.

### When ambiguous, ASK
```

Full text in `Rnkr69\LaraChatbot\Llm\SystemPromptBuilder::DEFAULT_DECISION_STRATEGY`.

---

## 3. How to enable / disable / customise

Lives in `config/chatbot.php → system_prompt.decision_strategy`:

```php
'system_prompt' => [
    'view'              => 'chatbot::system_prompt',
    'addendum_view'     => env('CHATBOT_SYSTEM_PROMPT_ADDENDUM', null),
    'decision_strategy' => env('CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY', true),
],
```

Three valid values:

| Value | Effect |
|---|---|
| `true` (default) | Emits the package's standard rules. |
| `false` | Disables the entire section. The LLM decides without specific guidance. |
| `'view::name'` | Renders that Blade view instead of the default. Useful for hosts that want a custom rule set with the same structure. |

### 3.1 Disable (not recommended)

```env
CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY=false
```

For hosts that do not use page context (pure chat, no admin) the section
is noise. For any admin web app, leave it active.

### 3.2 Customise via your own view

```env
CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY=chatbot.decision-strategy-custom
```

Publish `resources/views/chatbot/decision-strategy-custom.blade.php`:

```blade
{{-- Domain-specific decision rules --}}
## Page context — decision strategy

### When the user is on /captain/fleet
- Bulk approval actions (bulk_approve_missions) MUST be proposed with
  confirm=true. Never auto.
- If selected_ids is empty, suggest the user selects records first.

### When the user is on /pilot/dashboard
- ...
```

When you use a custom view it **replaces** the default rules — it does not
extend them. If you want to add rules WITHOUT losing the defaults, use the
`system_prompt.addendum_view` mechanism instead (see
[`getting-started.md`](getting-started.md)).

### 3.3 Combining rules: addendum_view to extend

`addendum_view` is concatenated at the end of the system prompt **in addition
to** the default rules. Recommended pattern:

```php
'system_prompt' => [
    'decision_strategy' => true,                                  // package default rules
    'addendum_view'     => 'chatbot.host-specific-rules',        // host-specific additional rules
],
```

The `host-specific-rules.blade.php` view then adds nuances ("on this host
financial actions always require double confirmation", etc.).

---

## 4. End-to-end patterns

### 4.1 Listing → filter the grid (no duplication)

**User** is on `/admin/mission` and types:
> "Filter by destination Mars and status approved"

**Page context** (abbreviated):
```json
{
  "crud": {
    "entity": "Mission",
    "action": "list",
    "filters": {
      "applied": {},
      "available": [
        {"name": "destination_planet_id", "type": "select",
         "options": [{"value": 1, "label": "Earth"}, {"value": 2, "label": "Mars"}]},
        {"name": "status", "type": "dropdown",
         "options": [{"value": "draft", "label": "Draft"}, {"value": "approved", "label": "Approved"}]}
      ]
    }
  }
}
```

**LLM decides (with rules active)**:
1. Same entity (`Mission`) and action `list` → page-bound.
2. Maps labels to values: `Mars → 2`, `approved → "approved"`.
3. Calls `fill_form(filters)` then `invoke_host_action('refreshGrid')`.
4. Confirms to the user in chat: "Filters applied. You can see the results above."

**Without the rules**, the LLM tends to call `list_missions(destination_planet_id=2, status='approved')` and emit a `render_block` table that duplicates the native grid.

### 4.2 Listing → different entity (chat-bound OK)

**User** is on `/admin/mission` but types:
> "How many ships do I have under maintenance?"

**Page context** says `entity: Mission`, but the question is about `Ship`.

**LLM decides**: different entity → chat-bound. Calls `list_ships(status='maintenance')` → render_block stats / table. Correct.

### 4.3 Detail view → edit the visible record

**User** is on `/admin/mission/25/show`, types:
> "Cancel this one"

**Page context**:
```json
{ "crud": { "entity": "Mission", "action": "show", "filters": {"mission_id": 25} } }
```

**LLM decides**: detail view + action on the visible record → calls `cancel_mission(mission_id=25)` directly (or the frontend wrapper with confirm). Does not call `mission_detail(25)` again to repaint the card.

### 4.4 Create form → fill_form

**User** is on `/admin/mission/create`, types:
> "Mission Earth → Mars, departure tomorrow 8:00, priority express"

**Page context** includes `crud.form` with the full schema (fields, FK options, types).

**LLM decides**:
1. Page action is `create` and a form schema is visible.
2. Resolves `Earth → 1`, `Mars → 2` from the select `options`.
3. Calls `fill_form(fields=[{name:'origin_planet_id',value:1}, ...])`.
4. The widget shows the confirm banner; the user reviews and submits.

### 4.5 Explicit ambiguity

**User** is on `/admin/mission`, types:
> "Put my missions from last month in a table right here in the chat"

**LLM** honours the explicit preference ("right here in the chat") even though the default would be page-bound: uses the backend tool + render_block. The rules include an explicit clause "Don't re-render in chat unless the user asks for 'in chat' / 'here'".

---

## 5. End-to-end tests (cookbook)

Standard pattern to verify that the LLM makes the correct decisions for
each combination of (page_context, user prompt). Measured with real traces
from a test host:

| # | Page | Prompt | Expected |
|---|---|---|---|
| 1 | `/admin/mission` (list) | "filter status approved + risk high" | `fill_form(filtersForm) + refreshGrid` |
| 2 | `/dashboard` (no CRUD) | "filter status approved + risk high" | `list_my_missions(...)` + `render_block` |
| 3 | `/admin/mission/25/show` | "cancel this one" | `cancel_mission(25)` (direct) |
| 4 | `/admin/mission?status=draft` with 8 selected | "approve these" | `approve_missions_bulk(target_ids=[...])` |
| 5 | `/admin/mission` (list) | "put a table right here" | `list_my_missions(...)` + `render_block` (respects explicit override) |

Test pattern in `tests/Feature/`:

```php
it('uses page-bound action when entity matches the listing', function () {
    $user = User::factory()->pilot()->create();
    Mission::factory()->for($user, 'pilot')->count(50)->create();

    $this->actingAs($user)
        ->withPageContext([
            'crud' => ['entity' => 'Mission', 'action' => 'list', 'filters' => ['available' => [
                ['name' => 'status', 'options' => ['draft', 'approved']],
            ]]],
        ])
        ->postJson('/chatbot/stream', ['message' => 'filtra por approved'])
        ->assertStream()
        ->expectsFrontendAction('fill_form');
});
```

(The `InteractsWithChatbot` testing harness that documents this was added in
v1.1.1.)

---

## 6. Debugging — inspecting what rules the LLM sees

The `chatbot:decision-rules:show` command prints the active rules:

```
$ php artisan chatbot:decision-rules:show

  Source:  package default
  Length:  1.8 KB

  ----------------------------------------------------------------------
  ## Page context — decision strategy
  ...
  ----------------------------------------------------------------------

  Addendum:  (none)
```

If the host configured `decision_strategy='view::name'`, the command
prints the render of that view instead of the default. Useful for
diagnosing "why did the LLM choose chat-block when it should have
filtered the grid?" — start by seeing what the LLM is actually reading.

---

## 7. Why this matters (beyond aesthetics)

- **Token economy**: 50 rows in a chat block = ~3000 output tokens +
  the block is persisted in `chatbot_messages` and re-enters the context
  on future turns. The native grid costs 0 LLM tokens.
- **Performance**: a render_block with 50 rows stretches the widget's
  shadow DOM; modifying filters on an existing DataTable is practically
  free.
- **Discoverability**: users learn the app better when the LLM "acts on"
  the UI they see instead of duplicating it. The LLM becomes a copilot
  over the app, not a substitute for it.
- **Consistency**: in successive turns, if the LLM filtered the grid in
  turn 1, turn 2 can refer to "the 50 we have on screen" without
  repainting anything. The conversation feels more natural.

---

## 8. References

- System prompt builder: `src/Llm/SystemPromptBuilder.php`
- Default rules: `SystemPromptBuilder::DEFAULT_DECISION_STRATEGY` constant
- Config key: `config/chatbot.php → system_prompt.decision_strategy`
- Page context provider Backpack: [`integrations/backpack.md`](integrations/backpack.md)
- Custom forms (no-Backpack): [`integrations/custom-forms.md`](integrations/custom-forms.md)
- Debug command: `php artisan chatbot:decision-rules:show`
