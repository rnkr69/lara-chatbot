# Troubleshooting

*English · [Español](troubleshooting.es.md)*

> Symptoms → likely causes → fix. Ordered from "most common" to "least common".
> If the exact symptom is not listed here, search by error code / exception class
> in the repo (`grep -r "ScopeResolverNotConfigured"` etc.) — package messages
> are specific by design ("fail loudly").
>
> Each entry has a code (`L1`, `M3`, …) so you can reference it in issues and
> other docs.

---

## L · LLM Connection

### L1 · `chatbot:test-connection` fails with timeout

**Symptom**:
```
ConnectException: cURL error 28: Operation timed out after 30000 milliseconds
```

**Cause**: the provider responds slowly or the network blocks outbound traffic.

**Fix**:
1. Verify the API key is valid and has not expired.
2. If behind a corporate proxy: configure `HTTP_PROXY`/`HTTPS_PROXY` in `.env`.
3. Raise the timeout in `config/services.php` for the Prism client:
   ```php
   'anthropic' => [
       'api_key' => env('ANTHROPIC_API_KEY'),
       'timeout' => 60,
   ],
   ```

### L2 · `LlmException: Provider returned 401 Unauthorized`

**Cause**: invalid API key, badly copied (spaces, line breaks), or from a
different project.

**Fix**:
- Re-read the key from the provider dashboard and replace it.
- Verify there are no spaces in `.env`: `chatbot:install` preserves existing
  keys; if you manually added `ANTHROPIC_API_KEY="sk-..."` with spaces around
  `=`, Laravel retains them.
- After editing `.env`: `php artisan config:clear`.

### L3 · `LlmException: model not found`

**Cause**: the model configured in `CHATBOT_MODEL` does not exist in the
provider, or is not enabled for your account (Claude Opus typically requires a
paid tier).

**Fix**:
- List available models from the provider and adjust `CHATBOT_MODEL`.
- For Claude: `claude-sonnet-4-6`, `claude-haiku-4-5-20251001`,
  `claude-opus-4-7` are the canonical IDs (cutoff 2026-01).

### L4 · LLM responds but "speaks English" when you expected another language

**Cause**: the base system prompt does not fix a language. The LLM mimics the
language of the last message, but sometimes defaults to English.

**Fix**: extend the system prompt addendum in
`resources/views/vendor/chatbot/system_prompt_addendum.blade.php`:

```blade
- Always respond in Spanish (Spain).
- If the user writes in another language, respond in that language.
```

> The addendum is published with `chatbot:install` (opt-in step) or with
> `vendor:publish --tag=chatbot-prompts`.

---

## M · Widget in the Browser

### M1 · Widget appears but does not respond

**Symptom**: click FAB → panel opens → you type → infinite spinner or
immediate stream close.

**Likely cause** (in order of frequency):

1. **Missing CSRF token**. The widget reads `<meta name="csrf-token">` from the
   head; if absent, the request is sent without a token and Laravel returns 419.
2. **CDN buffering the SSE**. Cloudflare/Fastly buffer `text/event-stream` by
   default unless you exclude the route from cache.
3. **Nginx `proxy_buffering on;`** ignores the `X-Accel-Buffering: no` header.

**Fix**:
1. Add `<meta name="csrf-token" content="{{ csrf_token() }}">` to `<head>`.
2. Exclude `/chatbot/stream*` from CDN cache. See
   [`deployment.md`](deployment.md).
3. Configure Nginx with `proxy_buffering off;` for that route. See
   [`deployment.md`](deployment.md).

### M2 · Widget does not appear

**Symptom**: no FAB in the corner, no console errors.

**Causes**:

1. The bundle is missing from `public/vendor/chatbot/chatbot-widget.js`:
   ```bash
   php artisan vendor:publish --tag=chatbot-assets --force
   ```
2. The layout does not include the snippet. Verify you have:
   ```html
   <chatbot-widget data-endpoint="/chatbot/stream"></chatbot-widget>
   <script src="/vendor/chatbot/chatbot-widget.js" defer></script>
   ```
3. The layout includes the snippet but it is placed before the closing
   `</head>` with `defer`. Fix the order: `<chatbot-widget>` just before
   `</body>`, `<script>` also before `</body>` or with `defer`/`async`.

### M3 · Widget appears but the panel looks "broken" / unstyled

**Cause**: the shadow DOM is normally isolated, but if your host injects styles
via `* { … }` with `!important` they can leak through. More commonly: the host
removes the package's internal `<style>` element due to a strict CSP directive.

**Fix**: add `style-src 'unsafe-inline'` to your CSP. The widget uses inline
styles because they are scoped to the shadow DOM (not global).

### M4 · "The widget closes by itself after a few seconds"

**Symptom**: the panel closes and returns to the FAB without a click.

**Cause**: the host has a script that fires `Chatbot.close()` or a
`window.dispatchEvent` that the widget interprets as a close signal.

**Fix**: in the console, before opening the widget:
```javascript
window.addEventListener('chatbot:state-change', e => console.log(e.detail));
```
then reproduce the close. Observe which event or method triggers it and review
your code.

### M5 · "I see two duplicate widgets"

**Cause**: the `<chatbot-widget>` snippet appears twice in the DOM (typical in
Inertia + an `app` layout applied to all views).

**Fix**: the web component is idempotent only if `window.Chatbot` was already
mounted, but the **tag** itself does duplicate. Make sure to include it **only
once** in your outermost layout.

---

## H · Tools

### H1 · The LLM does not use a new tool

**Symptom**: you run `chatbot:tools:list`, see the tool registered, but the
LLM does not invoke it even when you ask something that requires it.

**Likely cause**: the `description()` is not clear enough for the LLM to
decide to invoke it.

**Fix**: the LLM picks tools by description, not by name. Adjust:

```php
// Bad
public function description(): string { return 'List invoices.'; }

// Good
public function description(): string
{
    return 'Lists the current user\'s invoices with optional filters by '
         . 'status (paid|pending|cancelled) and a maximum row limit. '
         . 'Useful when the user asks about their invoices, outstanding '
         . 'payments, or wants to see recent history.';
}
```

> Rule: include **when** to invoke it (brief examples), not just what it does.

### H2 · `ScopeResolverNotConfiguredException`

**Symptom**:
```
Rnkr69\LaraChatbot\Authorization\Exceptions\ScopeResolverNotConfiguredException:
  ScopeResolver does not support scope Team.
```

**Cause**: the tool declares `defaultScope=Team`/`All` but the host does not
implement `ScopeResolver`, or left it returning `[]` (not implemented).

**Fix**: implement the resolver (see [`authorization.md`](authorization.md)):

```php
// app/Chatbot/Authorization/AppScopeResolver.php
public function resolveAccessibleUserIds(Authenticatable $user, AccessScope $scope): array
{
    return match ($scope) {
        AccessScope::Self => [$user->getAuthIdentifier()],
        AccessScope::Team => $user->reports()->pluck('id')->prepend($user->getAuthIdentifier())->all(),
        AccessScope::All  => User::query()->pluck('id')->all(),
    };
}
```

### H3 · `MissingTenantResolverException` at boot

**Symptom**: `php artisan serve` starts with:
```
Tool 'list_event_attendees' declares tenantScope=true but no
TenantResolver is registered.
```

**Fix**: implement and register the `TenantResolver`. See
[`authorization.md`](authorization.md).

### H4 · Backend tool with `confirmation=confirm` is never executed

**Symptom**: the LLM calls it but the orchestrator ignores it with a warning in
the logs:
```
Tool 'delete_record' declares confirmation=confirm; in v1 only Auto is
accepted for backend tools. Filtered from catalogue.
```

**By design**: in v1 backend tools only support `confirmation=auto`
(pausing/resuming the stream mid-turn is v2).

**Fix**: declare a **frontend tool** with `confirmation=confirm` that receives
the args and, once confirmed, dispatches the backend tool. See
[`backend-tools.md`](backend-tools.md).

### H5 · The tool executes twice for a single question

**Symptom**: `Log::audit` shows two calls to `list_invoices` for the same turn.

**Causes**:

1. **`ChatService` groups the LLM's tool calls**: if the LLM emits the same
   `tool_call` twice during streaming, `ChatService` executes them separately.
   This is not a package bug; it is LLM behaviour.
2. **`max_steps` allows multi-turn**: `chatbot.limits.max_steps=5` allows the
   LLM to iterate tool→LLM→tool. If the LLM considers the first result
   incomplete, it calls again.

**Fix**:
- For idempotency: your `handle()` should be idempotent for the same args
  (better pattern than chasing double invocations).
- To prevent re-invocation: improve `description()` quality so the LLM does
  not get confused. Or lower `max_steps` to 2 / 3.

---

## A · Frontend Actions

### A1 · `navigate` does not work in an SPA

**Symptom**: the LLM emits a `frontend_action` with `tool=navigate` but the
page does not change.

**Cause**: the widget detects MPA by default and calls `location.assign(url)`.
If your app is Inertia/Livewire, you need to register the SPA navigator:

**Fix**: in your JS bundle (after loading the widget):

```javascript
window.Chatbot.registerNavigator((url) => {
    if (window.Inertia) {
        window.Inertia.visit(url, { preserveScroll: true });
        return true; // handled by us
    }
    return false; // delegate to fallback (location.assign)
});
```

Alternative: add `<meta name="chatbot:runtime" content="spa">` to the layout
and the widget applies the heuristic detector (Inertia/Livewire/popstate). See
[`WIDGET.md`](WIDGET.md).

### A2 · `download_file` does not download anything

**Symptom**: the LLM emits a `frontend_action` with `tool=download_file` and
an id; the browser does not download.

**Causes**:

1. `chatbot.tools.download_file.allowed_disks` is empty (fail-secure default
   — without allowed disks all downloads fail with
   `ToolResult::error('disk_not_allowed', ...)`).
2. The returned URL is a raw `https://`/`http://` URL (rejected — only locally
   signed URLs are accepted).
3. The Blade `<a download>` extension is blocked by `default-src 'self'` CSP.

**Fix**:
1. In `config/chatbot.php`:
   ```php
   'tools' => [
       'download_file' => [
           'allowed_disks' => ['s3', 'local-private'],
           'max_expires_in' => 3600,
       ],
   ],
   ```
2. In your concrete tool, return a signable disk path, not an absolute URL.
3. CSP: add `connect-src 'self' https://your-cdn.com` if you sign S3 URLs.

### A3 · `fill_form` does not find the inputs

**Symptom**: the LLM says "I filled in the form" but the fields are still
empty.

**Likely cause**:
1. The `<form>` has no `id` or `data-chatbot-form`, and the page has more than
   one `<form>` (the v1.1.1 auto-discovery picks the first plausible one, which
   may not be the one the LLM intended).
2. The LLM calls with `name="X"` but no control in the form exposes that name
   or a `data-chatbot-field="X"` alias.
3. The widget loaded before the form (Inertia/Livewire later renders).

**Diagnosis**: open the DevTools console when the tool executes. Since v1.0.1
the primitive logs a `console.warn` with `availableNames` (combined list of
`name` and `data-chatbot-field` present in the form) when a field does not
match.

**Recommended fix** — mark the form and inputs with aliases:

```html
<form data-chatbot-form="invoice_create">
    <input name="customer_id" data-chatbot-field="customer" />
    <input name="amount" data-chatbot-field="amount" type="number" />
</form>
```

The LLM sees `customer` and `amount` (not the internal HTML names) and the
widget searches first by `[data-chatbot-field]`. If your host is Backpack or
uses custom Blade forms, automate this with the `@chatbotForm` directive or the
`chatbot:integrate-form <view>` command (v1.1.1) — see
[`integrations/custom-forms.md`](integrations/custom-forms.md).

---

## C · Confirmations

### C1 · The "Confirm / Cancel" banner does not appear

**Cause**: the tool declares `confirmation=auto` (default). For the banner to
appear, the **frontend** tool must declare `confirmation=confirm` or `manual`.

**Fix**:
```php
public function confirmation(): ConfirmationLevel
{
    return ConfirmationLevel::Confirm;
}
```

### C2 · "Confirm" returns 409 on the second call

**Symptom**: 1st call `{accept:true}` → 200 OK. 2nd call
`{accept:true,result:{done:true}}` → 409 Conflict.

**Cause**: the row is already in `executed` state (it was processed twice).

**Expected**: the endpoint is idempotent — a second call with the same accept
on a terminal row returns 409 with an informative body, it does **not** repeat
the action. Your client should treat 409 on `executed` as "already done".

**Client fix**: if the widget banner uses custom handling, treat `409` with
`state=executed` the same as `200`.

### C3 · Pending actions accumulate in the database

**Symptom**: `chatbot_pending_actions` has thousands of rows in `pending` state
with `expires_at` far in the past.

**Cause**: you are not running `chatbot:cleanup-actions` in the scheduler.

**Fix**: see [`deployment.md`](deployment.md).

---

## P · Page Context

### P1 · The bot does not "see" the current page context

**Symptom**: the user asks "what invoices are on screen?" and the bot responds
as if it knows nothing about the screen.

**Cause**:

1. You have not added `<meta name="chatbot:context">` to the layout/view.
2. The meta tag contains invalid JSON (badly escaped quotes).
3. The widget is in SPA mode but is not listening for `inertia:navigate` events.

**Fix**:
1. Add to the view:
   ```blade
   <meta name="chatbot:context" content='@json([
       "route" => "invoices.index",
       "filters" => ["status" => "unpaid"],
   ])'>
   ```
2. Verify the JSON in the console: `JSON.parse(document.querySelector('meta[name="chatbot:context"]').content)`.
3. SPA: the widget re-reads the meta tag after each `inertia:navigate`/`livewire:navigated`/`popstate`. If it does not, register in the console:
   ```javascript
   window.addEventListener('chatbot:context-changed', e => console.log(e.detail));
   ```
   and verify the event fires.

### P2 · "The page context is silently truncated"

**Symptom**: your meta tag has 50 KB of data but the LLM only sees a skeleton.

**Cause**: `chatbot.limits.page_context_kb` (default 16 KB) is exceeded; the
sanitizer discards the entire context.

**Fix**:
- Raise the limit if your use-case requires it: `'page_context_kb' => 64`.
- Better: reduce what you send in the meta tag. The bot does not need the full
  list of rows, only the shape of filters + visible IDs.

---

## E · Package Errors (boot)

### E1 · `RouteNotDefinedException: chatbot.stream`

**Cause**: the provider was not registered. Possible reasons:

1. `composer require` has not refreshed the autoloader.
2. Auto-discovery is disabled in your `composer.json`:
   ```json
   "extra": {
       "laravel": {
           "dont-discover": ["rnkr69/lara-chatbot"]
       }
   }
   ```

**Fix**:
```bash
composer dump-autoload
php artisan config:clear
php artisan route:list --name=chatbot
```

### E2 · `ConfigurationException: Provider X not supported by Prism`

**Cause**: `CHATBOT_PROVIDER` points to a name Prism does not recognise. Prism
uses a convention: `anthropic`, `openai`, `groq`, `gemini`, `mistral`,
`ollama`. There is no `google` (use `gemini`) or `chatgpt` (use `openai`).

**Fix**: use the canonical Prism names. See
[`getting-started.md`](getting-started.md).

### E3 · `MigrationException: table chatbot_conversations already exists`

**Cause**: you ran `chatbot:install --force` and published migrations, but they
were already applied in a previous run.

**Fix**:
```bash
# Option A: rollback and re-run
php artisan migrate:rollback --path=database/migrations/2026_05_08_000001_create_chatbot_conversations_table.php
php artisan migrate

# Option B: mark as already run (if the data is already correct)
php artisan migrate --pretend
# If all OK, mark the batch manually in the migrations table.
```

---

## D · Advanced Debugging

### D1 · Enable verbose package logging

```env
LOG_CHANNEL=stack
LOG_LEVEL=debug
```

```php
// config/logging.php
'channels' => [
    'chatbot' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/chatbot.log'),
        'level'  => 'debug',
        'days'   => 7,
    ],
],
```

```php
// app/Providers/AppServiceProvider.php
Event::listen(\Rnkr69\LaraChatbot\Events\ToolInvoked::class, function ($event) {
    Log::channel('chatbot')->debug('tool', [
        'tool' => $event->tool->name(),
        'args' => $event->args,
        'result' => $event->result->toArray(),
        'duration_ms' => $event->durationMs,
    ]);
});
```

### D2 · Inspect the actual system prompt sent to the LLM

The system prompt is composed via `SystemPromptBuilder` (publishable view +
addendum + `## Current page` + `## Pending actions`). To view it cleanly
during a turn:

```php
// Temporary dd-debug in SystemPromptBuilder::build()
\Log::channel('chatbot')->debug('system_prompt', ['body' => $body]);
```

Or from outside the package, without touching the core:

```php
$prompt = app(\Rnkr69\LaraChatbot\Llm\SystemPromptBuilder::class)
    ->build(auth()->user(), $tools = collect(), $pageContext = []);

dd($prompt);
```

### D3 · Reproduce a failure in tests

When a user reports a bug, the fastest pattern is:

```php
// tests/Feature/RegressionTest.php (in the host)
test('reproduce bug X', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chatbot/stream', [
        'message' => 'the exact user message',
        'page_context' => ['route' => 'invoices.show', 'invoice_id' => 123],
    ]);

    $response->assertOk();
    // ...
});
```

Mock Prism with `Prism::fake()` to avoid the real LLM call. See
[`testing.md`](testing.md).

---

## F · FAQ

**Q: Does it work with Laravel 10?**

Not in v1. If your app is on L10, plan the upgrade — `prism-php/prism` also
requires ^11.

**Q: Does it work with Octane / FrankenPHP?**

Yes. SSE under Swoole works from 4.x. Under FrankenPHP, make sure to disable
buffering and raise timeouts. See [`deployment.md`](deployment.md).

**Q: Can I use my own custom LLM (not supported by Prism)?**

Yes, but it requires extending `LlmGateway`. This is not a supported path in
v1; consider whether your LLM can be exposed via an OpenAI-compatible endpoint
(LM Studio, vLLM, etc.) — Prism supports OpenAI-compatible endpoints.

**Q: How do I completely disable the chatbot in an environment?**

```env
# Remove the <chatbot-widget> snippet from the layout, or
CHATBOT_ENABLED=false
```

And in `routes/web.php`:
```php
if (config('chatbot.enabled', true)) {
    Route::loadFrom(base_path('vendor/rnkr69/lara-chatbot/routes/chatbot.php'));
}
```

**Q: Can I change the `/chatbot` route prefix?**

Yes, in `config/chatbot.php`:
```php
'route' => [
    'prefix' => 'assistant',
    'middleware' => ['web', 'auth'],
    'as' => 'assistant.',
],
```

After this, the endpoint becomes `/assistant/stream`. Remember to update the
`data-endpoint` attribute on the widget.

**Q: How do I access the current `Authenticatable` from a tool?**

The `ToolContext` received by `handle()` exposes it:
```php
public function handle(array $args, ToolContext $ctx): ToolResult
{
    $user = $ctx->user;          // Authenticatable
    $convo = $ctx->conversation; // ?Conversation
    $page = $ctx->pageContext;   // array
    // ...
}
```

---

## Support

- For reproducible bugs: include the package version (`composer show rnkr69/lara-chatbot`),
  Laravel version, LLM provider, a log excerpt with `LOG_LEVEL=debug`, and a
  code snippet that reproduces the problem.
- For general questions: read `getting-started.md` + `authorization.md` +
  this page before asking.
