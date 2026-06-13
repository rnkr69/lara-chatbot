# Deployment

*English · [Español](deployment.es.md)*

> Operational guide for taking `rnkr69/lara-chatbot` to production. Covers what
> changes compared to a "normal" Laravel app: SSE configuration behind proxies,
> rate limiting, widget bundle deployment, LLM key management, scheduler for
> cleanup, CSP hardening, and monitoring.
>
> Pre-reading: [`getting-started.md`](getting-started.md) (local installation).

---

## 1. Executive summary

`rnkr69/lara-chatbot` is **stateless** from a deployment standpoint:

- State lives in the database (`chatbot_conversations`, `chatbot_messages`,
  `chatbot_pending_actions`).
- The widget bundle is a static asset served from `public/`.
- SSE streaming is standard HTTP — it scales like any Laravel endpoint,
  with two specific constraints detailed below.

Three things can break in production if you skip them: **proxy buffering**,
**SSE timeout**, and **output flush**. These are covered in section [§2](#2-sse-behind-proxies).

---

## 2. SSE behind proxies

### 2.1 Headers sent by the package

`ChatController@stream` already emits the correct headers:

```http
Content-Type: text/event-stream; charset=UTF-8
Cache-Control: no-cache, private
X-Accel-Buffering: no
Connection: keep-alive
```

But proxies between the client and PHP-FPM may ignore them. The general rule:
**disable buffering**, **increase read timeout**, **no chunking**.

### 2.2 Nginx

```nginx
location /chatbot/stream {
    proxy_pass http://php-fpm;
    proxy_http_version       1.1;
    proxy_set_header         Connection "";

    # Critical for SSE
    proxy_buffering          off;
    proxy_cache              off;
    proxy_read_timeout       600s;   # covers long conversations
    proxy_send_timeout       600s;
    chunked_transfer_encoding off;

    # Pass headers as-is
    add_header X-Accel-Buffering no;
}
```

> **Note**: `proxy_buffering off;` is what truly matters. The
> `X-Accel-Buffering: no` header already emitted by the controller is the signal
> *when Nginx sits in front with default configuration*; if you have set
> `proxy_buffering on;` globally, the header is not enough.

### 2.3 Apache + mod_proxy

```apache
<Location /chatbot/stream>
    ProxyPass        http://localhost:9000/chatbot/stream
    ProxyPassReverse http://localhost:9000/chatbot/stream
    SetEnv proxy-flushpackets 1
    SetEnv proxy-nokeepalive 0
    LimitRequestBody 0
</Location>

# Long timeout (default 60s)
ProxyTimeout 600
```

### 2.4 Cloudflare / CDNs

Typical CDN services (Cloudflare, Fastly, CloudFront) buffer the stream and
**break SSE**. Exclude `/chatbot/stream` from cacheable passthrough:

- **Cloudflare**: Page Rules → URL match `*/chatbot/stream*` → "Cache Level: Bypass".
- **CloudFront**: Behavior `path-pattern=/chatbot/stream*` → "Cache policy: CachingDisabled".

### 2.5 PHP-FPM

```ini
; php.ini or specific pool
output_buffering = Off
implicit_flush = On
zlib.output_compression = Off

; Worker execution timeout
max_execution_time = 0    ; SSE can live longer than the default 30s
```

> The controller calls `@flush()` after each frame and `ob_implicit_flush(true)`
> when opening the stream — but if your pool has a numeric `output_buffering`
> (default `4096`), PHP still buffers until it fills. Make sure it is **`Off`**.

### 2.6 Laravel Octane

If your host runs Octane (Swoole / RoadRunner), the SSE endpoint
**works but requires care**:

- Octane reuses workers between requests; a long stream blocks that worker
  for the entire turn. Size `--workers` to absorb concurrency.
- Under Swoole, set `swoole.output_buffer_size = 0` and disable
  `enable_static_handler` for the `/chatbot/*` route.
- The `connection_aborted()` the package uses to detect client disconnection
  behaves correctly under Swoole since 4.x.

---

## 3. Timeouts and long-lived connections

| Layer | Typical default | Recommended for SSE |
|---|---|---|
| Nginx `proxy_read_timeout` | 60s | **600s** |
| Apache `ProxyTimeout` | 60s | **600s** |
| PHP-FPM `request_terminate_timeout` | 30s | **0** (unlimited) or 600s |
| `max_execution_time` (CLI/web) | 30s | **0** |
| Browser fetch / EventSource | no limit | n/a — the client closes when the user leaves |

The widget automatically retries with exponential backoff 1s→30s + 25% jitter
if the connection dies prematurely.

---

## 4. Rate limiting

Configured in `chatbot.limits.rate_limit`:

```php
'rate_limit' => [
    'enabled'             => true,
    'requests_per_minute' => 30,
],
```

`ChatController::checkRateLimit()` counts hits per user in `cache()` with key
`chatbot:stream:{user_id}`. When exceeded:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 47
Content-Type: application/json

{"error":"rate_limited","retry_after":47}
```

### 4.1 Recommendations

- **Production**: 30/min covers natural conversational use. Lower it if your LLM
  is expensive.
- **Tier-aware**: if your app has plans (free/pro/enterprise), wrap the route in
  a custom middleware that overrides the limit per user:

```php
// routes/web.php
Route::middleware(['auth', \App\Http\Middleware\ChatbotTierLimit::class])
    ->group(fn () => Route::loadFrom(base_path('vendor/rnkr69/lara-chatbot/routes/chatbot.php')));
```

- **Distributed**: Laravel's cache backend is the source of truth. In
  multi-server setups, use Redis (not `array`/`file`).

### 4.2 Per-tool rate limit (future)

`chatbot.limits.rate_limit_per_tool` is reserved in the config as a hook
for v1.1. In the meantime, tools that call external APIs must apply their
own throttling inside `handle()`.

---

## 5. Widget bundle distribution

The precompiled bundle lives in `vendor/rnkr69/lara-chatbot/public-build/chatbot-widget.js`
(~28 KB gzip / ~101 KB raw).

### 5.1 Publish to `public/`

```bash
php artisan vendor:publish --tag=chatbot-assets --force
```

This copies the bundle to `public/vendor/chatbot/chatbot-widget.js`. The host
layout loads it with `<script src="{{ asset('vendor/chatbot/chatbot-widget.js') }}" defer>`.

### 5.2 Serving from a CDN

If your app serves static assets from a CDN (CloudFront, Cloudflare R2…):

1. Move `public/vendor/chatbot/chatbot-widget.js` to your CDN during deploy.
2. Change the `<script>` `src` to point to the CDN.
3. **Cache headers**: `Cache-Control: public, max-age=31536000, immutable`
   (the bundle changes with each release, not each deploy).
4. Version with a query string for cache busting: `?v={{ filemtime(public_path('vendor/chatbot/chatbot-widget.js')) }}` — busts the cache automatically whenever the published asset changes.

### 5.3 Custom build from the host

If you need to patch the bundle (e.g. add custom telemetry, change the markdown
subset), copy `resources/js/` from the package into your app and compile from
there. Details in [`WIDGET.md`](WIDGET.md).

> **Trade-off**: you lose future package JS updates. Consider using
> `registerTool` / `registerBlockRenderer` / `registerNavigator` over the
> standard bundle first.

---

## 6. Environment variables

| Var | Description | Default |
|---|---|---|
| `CHATBOT_PROVIDER` | Prism provider (`anthropic`/`openai`/`groq`/`gemini`/`mistral`/`ollama`) | `anthropic` |
| `CHATBOT_MODEL` | Provider model | `claude-sonnet-4-6` |
| `CHATBOT_AUTH_RESOLVER` | `spatie`/`gate`/`custom` | `spatie` |
| `ANTHROPIC_API_KEY` | API key for the corresponding provider | — |
| `OPENAI_API_KEY` | same | — |
| `GROQ_API_KEY` | same | — |
| `GEMINI_API_KEY` | same | — |
| `MISTRAL_API_KEY` | same | — |
| `OLLAMA_URL` | local Ollama host | `http://localhost:11434` |

### 6.1 Best practices

- API keys in a secret manager (Vault, AWS SM, Doppler), not in plain `.env`.
- Different roles per environment: `staging` can use Claude Haiku to reduce
  costs; `production` uses Sonnet/Opus.
- When changing a secret, redeploy or run `php artisan config:clear`.

---

## 7. Scheduler and concurrency

The package exposes two schedulable commands: one mandatory
(`chatbot:cleanup-actions`) and one optional only for hosts with the
dashboard enabled (`chatbot:dashboards:prune`, v2.0):

```php
// app/Console/Kernel.php — Laravel 11 / 12
use Illuminate\Console\Scheduling\Schedule;

protected function schedule(Schedule $schedule): void
{
    // Required: cleans up expired chatbot_pending_actions.
    $schedule->command('chatbot:cleanup-actions')
             ->hourly()
             ->withoutOverlapping();

    // Optional (only if CHATBOT_DASHBOARD_ENABLED=true): Personal Dashboard
    // housekeeping. Without flags the command exits with an error — always
    // declare explicitly what it prunes. See docs/dashboard.md §9.3.
    $schedule->command('chatbot:dashboards:prune', [
                 '--source-missing', '--stale', '--empty-dashboards', '--force',
             ])
             ->weekly()
             ->withoutOverlapping();

    // (Optional) monthly hard-delete of rows soft-deleted more than 30 days ago:
    // $schedule->command('chatbot:dashboards:prune', [
    //              '--purge-soft-deleted', '--force',
    //          ])->monthly()->withoutOverlapping();
}
```

**`chatbot:cleanup-actions`**: marks as `expired` any `pending_action` with
`expires_at < now()` — soft, **no DELETE** (preserves audit trail and avoids
noise in the `## Pending actions` section of the prompt).
Details in [`confirmation-flow.md`](confirmation-flow.md).

**`chatbot:dashboards:prune`** (v2.0): soft-deletes unusable widgets (tool
disappeared, refresh too old, empty dashboards) and optionally hard-deletes
already soft-deleted rows. Four opt-in modes; dry-run by default, `--force`
executes. Without flags the command exits with an error. Default thresholds
30/90/180/30 days, configurable via `chatbot.dashboard.prune.*` or CLI
override. Full flag documentation + scheduler recipe in [`dashboard.md`](dashboard.md).

**Recommended frequency**:

- **`chatbot:cleanup-actions`**: `hourly` by default;
  `everyTenMinutes` if you have many `confirm` actions (TTL 10 min) and notice
  that expired ones are slow to disappear from the prompt.
- **`chatbot:dashboards:prune`**: `weekly` covers most hosts; the default
  thresholds (30/90/180 days) are conservative. If the host has a very large
  number of pinned widgets and a frequently changing `ToolRegistry`, consider
  `daily` with `--source-missing` in isolation.

### 7.5 Concurrency (Personal Dashboard replay)

The dashboard bulk refresh (`POST /chatbot/dashboards/{slug}/refresh`, v2.0)
re-executes each widget's tools with `Concurrency::run()`, chunked to the
`chatbot.dashboard.replay.concurrency` cap (default 8). The driver is chosen
by the host via `config/concurrency.php`:

```php
// config/concurrency.php — publish with:
//   php artisan config:publish concurrency
return [
    // 'sync' (safe in any environment), 'process' or 'fork'.
    'default' => env('CONCURRENCY_DRIVER', 'sync'),
];
```

| Driver | When | Notes |
|--------|------|-------|
| `sync` | **Safe default.** Windows/WAMP, shared hosting, containers without `pcntl`. | Runs replays sequentially in the same process. No parallelism, but no surprises. For dashboards with few widgets the sequential cost is imperceptible. |
| `process` | Hosts with standard PHP-FPM and ability to spawn `artisan` subprocesses. | True parallelism. Each task is serialized and runs in a subprocess with a fresh framework boot — the host must ensure its tools are registered during boot (service provider/config), not at runtime. |
| `fork` | Hosts with the `pcntl` extension (CLI/Octane, not FPM web). | Parallelizes without re-booting; the fastest option where available. |

**Important** — Laravel 11+ does NOT publish `config/concurrency.php` by
default, so without publishing it `concurrency.default` falls back to the
hardcoded `process`. If the host has no viable subprocess (typical on
Windows/WAMP), the bulk refresh will fail. Publish the config and set `sync`
unless your infrastructure supports `process`/`fork`.

Tasks the package passes to `Concurrency::run()` are serializable-friendly:
`static` closures that do NOT capture the `ReplayService` graph (only the
widget + the user), so any driver is safe from v2.1.0 onwards. In v2.0.0 the
task captured `$this` and exhausted memory under `process`/`fork` — if you
come from v2.0.0 with the `config/concurrency.php` workaround set to `sync`,
you can keep it or upgrade to `process`/`fork` after updating.

---

## 8. Hardening

### 8.1 CSP (Content Security Policy)

The widget lives in a shadow DOM and does not inject inline scripts. Minimum CSP:

```
default-src 'self';
script-src  'self';
style-src   'self' 'unsafe-inline';   /* shadow DOM injects its own <style> */
connect-src 'self';                   /* /chatbot/stream */
img-src     'self' data:;             /* avatars and card blocks with images */
```

If you serve the bundle from a CDN, add `script-src 'self' https://cdn.your-host.com`.

### 8.2 CSRF

`POST /chatbot/stream` and `POST /chatbot/actions/{id}/confirm` are in the
`web` group and require a CSRF token. The widget reads it automatically from
the `<meta name="csrf-token" content="{{ csrf_token() }}">` meta tag that
Laravel adds by default.

If your app does not include that meta tag, add it to your main layout.

### 8.3 Sensitive data redaction

The package does **not** automatically redact PII in logs or `ToolInvoked`
events. If your tools handle sensitive data (PII, health, financial), implement
redaction in the listener:

```php
Event::listen(ToolInvoked::class, function ($event) {
    $args = collect($event->args)
        ->map(fn ($v, $k) => str_contains($k, 'email') ? '[REDACTED]' : $v)
        ->all();

    Log::channel('audit')->info('chatbot.tool', compact('args') + [...]);
});
```

### 8.4 Page context

Users can manipulate the `<meta name="chatbot:context">`. **Never** rely on
page context for backend authorization decisions without re-validating:

```php
// BAD — trusts something the client can forge
$tenantId = $pageContext['tenant_id'];

// GOOD — validates that the user actually has access to that tenant
if (! $user->tenants()->where('id', $tenantId)->exists()) {
    return ToolResult::error('out_of_scope', 'Not accessible.');
}
```

If you extend the cascade with page context data, maintain the same discipline.

---

## 9. Observability

### 9.1 Logs

| Channel | What to log | Purpose |
|---|---|---|
| `chatbot` or `audit` | `ToolInvoked` with user/tool/result/duration | legal/compliance traceability |
| `default` | package warnings (page_context overflow, unresolved scope) | package health |
| `slow` | tools with `duration > 5s` | optimization |

### 9.2 Metrics (Prometheus / StatsD)

Useful metrics to alert on:

- `chatbot.stream.requests_total{provider,model}` — counter.
- `chatbot.stream.duration_seconds{provider,model}` — histogram.
- `chatbot.tool.invocations_total{tool,result}` — counter.
- `chatbot.tool.duration_seconds{tool}` — histogram.
- `chatbot.pending_actions{state}` — gauge (fed by a job that counts rows by
  status).

Hook it up from a `ToolInvoked` listener that calls `\Statsd::*` or similar.

### 9.3 Distributed traces

The SSE stream passes through several layers (controller → ChatService → Prism →
provider). If you use OpenTelemetry, instrument the two external calls:

- Prism → provider call (HTTP outbound).
- Host backend tools (each `handle()` can be a span).

The package does not instrument automatically; the host injects span tags via
a `ToolInvoked` listener and standard HTTP middlewares.

---

## 10. Deployment

### 10.1 Canonical CI/CD steps

```bash
# Build
composer install --no-dev --optimize-autoloader
npm ci && npm run build       # only if compiling a custom widget

# Deploy
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan vendor:publish --tag=chatbot-assets --force

# Restart workers
php artisan queue:restart    # if you use queues
sudo systemctl reload php-fpm
```

### 10.2 Post-deploy smoke test

```bash
# Active provider
php artisan chatbot:test-connection

# Registered tools
php artisan chatbot:tools:list
```

If both pass, the critical components are healthy.

### 10.3 Rolling deployments

`/chatbot/stream` keeps the connection open for minutes. During a rolling
deploy, in-flight streams are cut when the old worker is killed. The widget
retries with backoff — users see a 1–2s freeze and continue.

If your SLA requires zero-disconnect, consider:

- Announcing the deploy in the chat with a `text` block before the cut
  ("We're deploying improvements, connection will resume in 30s").
- Drain mode: stop accepting new SSE requests but serve the open ones until
  they finish.

### 10.4 Rollback

`rnkr69/lara-chatbot` follows SemVer. Rolling back a minor or patch release:

```bash
composer require rnkr69/lara-chatbot:0.4.0 --no-update
composer update rnkr69/lara-chatbot --with-dependencies
php artisan migrate --rollback     # ONLY if the new version added migrations
php artisan vendor:publish --tag=chatbot-assets --force
```

> Note: we are on `0.x`, so MINOR versions may break. Pin to an exact `0.4.N`
> version for production and review the CHANGELOG before upgrading.

Package migrations are additive; a rollback may leave extra columns that cause
no harm. Breaking-change policy details are in `CHANGELOG.md` under
"Versioning policy".

---

## 11. Costs

The dominant cost in production is the **LLM provider**. Estimate:

| Provider · Model | Cost per typical turn (1k prompt tokens + 500 out) |
|---|---|
| Claude Sonnet 4.6 | ~$0.005 |
| Claude Opus 4.7 | ~$0.025 |
| OpenAI GPT-4.1 | ~$0.005 |
| Gemini 2.5 Pro | ~$0.003 |
| Groq Llama 3.3 70B | ~$0.0005 |
| Ollama local | $0 (own compute) |

To reduce costs:

- **History truncation**: `chatbot.limits.history_messages = 20` trims the
  history sent to the LLM (older messages remain in the database). Lower it
  if turns do not need that much context.
- **Cache**: Anthropic and OpenAI support prompt caching. Prism does not yet
  expose this uniformly; if your provider supports it, consider sending the
  system prompt in an initial cacheable request.
- **Smaller model for trivial turns**: implement a pre-LLM heuristic (e.g.
  simple greeting → skip the LLM, return a canned response).

---

## 12. Pre-production checklist

- [ ] Nginx/Apache configured with `proxy_buffering off` for `/chatbot/stream`.
- [ ] PHP-FPM `output_buffering = Off`.
- [ ] CDN excluding `/chatbot/stream` from cacheable passthrough.
- [ ] `chatbot:cleanup-actions` scheduled in the scheduler.
- [ ] (Dashboard v2.0 only) `chatbot:dashboards:prune` scheduled with the flags and cadence appropriate for the host's volume (see §7 + [`dashboard.md`](dashboard.md)).
- [ ] `chatbot:test-connection` green in the target environment.
- [ ] Rate limit tuned to expected volume.
- [ ] Widget bundle published to `public/` (or uploaded to CDN).
- [ ] `ToolInvoked` listener registered for auditing.
- [ ] Host CSP updated (at minimum `style-src 'unsafe-inline'`).
- [ ] Migrations applied (`migrate --force`).
- [ ] Environment variables with API keys configured.
- [ ] Smoke test in staging: the widget appears, responds, calls a tool, the
      tool authorizes and returns correct data for the logged-in user.
- [ ] Provider metrics exported (at minimum cost and P95 latency).

---

## 13. References

- Configuration: `config/chatbot.php` (`limits` and `route` sections).
- SSE controller: `src/Http/Controllers/ChatController.php`.
- Cleanup command: `src/Console/Commands/CleanupActionsCommand.php`.
- Widget: [`WIDGET.md`](WIDGET.md).
- Distribution / CI matrix: [`distribution.md`](distribution.md).
- Runtime troubleshooting: [`troubleshooting.md`](troubleshooting.md).
