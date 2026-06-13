# Deployment

*[English](deployment.md) · Español*

> Guía operativa para llevar `rnkr69/lara-chatbot` a producción. Cubre lo que cambia
> respecto a una app Laravel "normal": configuración SSE detrás de proxies, rate
> limiting, despliegue del bundle del widget, gestión de claves LLM, scheduler
> para limpieza, hardening de CSP y monitorización.
>
> Pre-lectura: [`getting-started.md`](getting-started.es.md) (instalación local).

---

## 1. Resumen ejecutivo

`rnkr69/lara-chatbot` es **stateless** desde el punto de vista de despliegue:

- El estado vive en la BD (`chatbot_conversations`, `chatbot_messages`,
  `chatbot_pending_actions`).
- El bundle del widget es un asset estático servido desde `public/`.
- El streaming SSE es HTTP estándar — escala como cualquier endpoint Laravel,
  con dos restricciones específicas que detallamos a continuación.

Tres cosas pueden romper en producción si las omites: **proxy buffering**,
**timeout SSE** y **flush de output**. Las cubre la sección [§2](#2-sse-detras-de-proxies).

---

## 2. SSE detrás de proxies

### 2.1 Headers que envía el paquete

`ChatController@stream` ya emite los headers correctos:

```http
Content-Type: text/event-stream; charset=UTF-8
Cache-Control: no-cache, private
X-Accel-Buffering: no
Connection: keep-alive
```

Pero los proxies entre el cliente y PHP-FPM pueden ignorarlos. La regla general:
**deshabilitar buffering**, **subir timeout de lectura**, **no chunking**.

### 2.2 Nginx

```nginx
location /chatbot/stream {
    proxy_pass http://php-fpm;
    proxy_http_version       1.1;
    proxy_set_header         Connection "";

    # Crítico para SSE
    proxy_buffering          off;
    proxy_cache              off;
    proxy_read_timeout       600s;   # cubre conversaciones largas
    proxy_send_timeout       600s;
    chunked_transfer_encoding off;

    # Pasar headers tal cual
    add_header X-Accel-Buffering no;
}
```

> **Nota**: `proxy_buffering off;` es lo que de verdad importa. El header
> `X-Accel-Buffering: no` que ya emite el controller es la señal *cuando hay
> Nginx delante con configuración default*; si has tocado `proxy_buffering on;`
> globalmente, el header no es suficiente.

### 2.3 Apache + mod_proxy

```apache
<Location /chatbot/stream>
    ProxyPass        http://localhost:9000/chatbot/stream
    ProxyPassReverse http://localhost:9000/chatbot/stream
    SetEnv proxy-flushpackets 1
    SetEnv proxy-nokeepalive 0
    LimitRequestBody 0
</Location>

# Timeout largo (default 60s)
ProxyTimeout 600
```

### 2.4 Cloudflare / CDNs

Servicios CDN típicos (Cloudflare, Fastly, CloudFront) buffer-ean el stream y
**rompen SSE**. Excluye `/chatbot/stream` del passthrough cacheable:

- **Cloudflare**: Page Rules → URL match `*/chatbot/stream*` → "Cache Level: Bypass".
- **CloudFront**: Behavior `path-pattern=/chatbot/stream*` → "Cache policy: CachingDisabled".

### 2.5 PHP-FPM

```ini
; php.ini o pool específico
output_buffering = Off
implicit_flush = On
zlib.output_compression = Off

; Timeout de execución del worker
max_execution_time = 0    ; SSE puede vivir más que el default 30s
```

> El controller llama `@flush()` tras cada frame y `ob_implicit_flush(true)` al
> abrir el stream — pero si tu pool tiene `output_buffering` numérico (default
> `4096`), PHP igual buffer-ea hasta que se llena. Asegúrate de **`Off`**.

### 2.6 Laravel Octane

Si tu host corre Octane (Swoole / RoadRunner), el endpoint SSE
**funciona pero con cuidado**:

- Octane reusa workers entre requests; un stream largo bloquea ese worker
  durante todo el turno. Dimensiona `--workers` para absorber concurrencia.
- En Swoole, `swoole.output_buffer_size = 0` y desactivar `enable_static_handler`
  en la ruta `/chatbot/*`.
- El `connection_aborted()` que el paquete usa para detectar cierre del cliente
  se comporta correctamente bajo Swoole desde 4.x.

---

## 3. Timeouts y conexiones largas

| Capa | Default típico | Recomendado para SSE |
|---|---|---|
| Nginx `proxy_read_timeout` | 60s | **600s** |
| Apache `ProxyTimeout` | 60s | **600s** |
| PHP-FPM `request_terminate_timeout` | 30s | **0** (sin límite) o 600s |
| `max_execution_time` (CLI/web) | 30s | **0** |
| Browser fetch / EventSource | sin límite | n/a — el cliente cierra cuando el usuario sale |

El widget reintenta automáticamente con backoff exponencial 1s→30s + jitter 25%
si la conexión muere prematuramente.

---

## 4. Rate limiting

Configurado en `chatbot.limits.rate_limit`:

```php
'rate_limit' => [
    'enabled'             => true,
    'requests_per_minute' => 30,
],
```

`ChatController::checkRateLimit()` cuenta hits por usuario en `cache()` con clave
`chatbot:stream:{user_id}`. Cuando se excede:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 47
Content-Type: application/json

{"error":"rate_limited","retry_after":47}
```

### 4.1 Recomendaciones

- **Producción**: 30/min cubre uso conversacional natural. Bájalo si tu LLM
  cuesta caro.
- **Tier-aware**: si tu app tiene planes (free/pro/enterprise), envuelve la
  ruta en un middleware propio que sobrescriba el límite por usuario:

```php
// routes/web.php
Route::middleware(['auth', \App\Http\Middleware\ChatbotTierLimit::class])
    ->group(fn () => Route::loadFrom(base_path('vendor/rnkr69/lara-chatbot/routes/chatbot.php')));
```

- **Distribuido**: el cache backend de Laravel es la fuente de truth. En
  multi-server, usa Redis (no `array`/`file`).

### 4.2 Rate limit por tool (futuro)

`chatbot.limits.rate_limit_per_tool` está reservado en la config como hook
para v1.1. Mientras tanto, las tools que llaman APIs externas deben aplicar su
propio throttling dentro de `handle()`.

---

## 5. Distribución del bundle del widget

El bundle precompilado vive en `vendor/rnkr69/lara-chatbot/public-build/chatbot-widget.js`
(13.75 KB gzip / 47.62 KB raw).

### 5.1 Publicar a `public/`

```bash
php artisan vendor:publish --tag=chatbot-assets --force
```

Esto copia el bundle a `public/vendor/chatbot/chatbot-widget.js`. El layout
host lo carga con `<script src="{{ asset('vendor/chatbot/chatbot-widget.js') }}" defer>`.

### 5.2 Servir desde CDN

Si tu app sirve assets estáticos desde un CDN (CloudFront, Cloudflare R2…):

1. Mueve `public/vendor/chatbot/chatbot-widget.js` a tu CDN durante el deploy.
2. Cambia el `src` del `<script>` apuntando al CDN.
3. **Cache headers**: `Cache-Control: public, max-age=31536000, immutable`
   (el bundle cambia con cada release, no con cada deploy).
4. Versiona con query string para bust cache: `?v={{ config('chatbot.version') }}`.

### 5.3 Build custom desde el host

Si necesitas patchar el bundle (e.g. añadir telemetría custom, cambiar el
markdown subset), copia `resources/js/` del paquete a tu app y compílalo desde
ahí. Detalle en [`WIDGET.md`](WIDGET.es.md).

> **Trade-off**: pierdes los updates futuros del paquete en JS. Considera
> primero usar `registerTool` / `registerBlockRenderer` / `registerNavigator`
> sobre el bundle estándar.

---

## 6. Variables de entorno

| Var | Descripción | Default |
|---|---|---|
| `CHATBOT_PROVIDER` | Provider Prism (`anthropic`/`openai`/`groq`/`gemini`/`mistral`/`ollama`) | `anthropic` |
| `CHATBOT_MODEL` | Modelo del provider | `claude-sonnet-4-6` |
| `CHATBOT_AUTH_RESOLVER` | `spatie`/`gate`/`custom` | `spatie` |
| `ANTHROPIC_API_KEY` | API key del provider correspondiente | — |
| `OPENAI_API_KEY` | idem | — |
| `GROQ_API_KEY` | idem | — |
| `GEMINI_API_KEY` | idem | — |
| `MISTRAL_API_KEY` | idem | — |
| `OLLAMA_URL` | host del Ollama local | `http://localhost:11434` |

### 6.1 Buenas prácticas

- API keys en secret manager (Vault, AWS SM, doppler), no en `.env` plain.
- Roles distintos por entorno: `staging` puede usar Claude Haiku para abaratar;
  `production` usa Sonnet/Opus.
- Cuando cambies un secret, redeploy o `php artisan config:clear`.

---

## 7. Scheduler y concurrencia

El paquete expone dos comandos schedulables: uno obligatorio
(`chatbot:cleanup-actions`) y otro opcional sólo para hosts con
dashboard habilitado (`chatbot:dashboards:prune`, v2.0):

```php
// app/Console/Kernel.php — Laravel 11 / 12
use Illuminate\Console\Scheduling\Schedule;

protected function schedule(Schedule $schedule): void
{
    // Obligatorio: limpia chatbot_pending_actions caducados.
    $schedule->command('chatbot:cleanup-actions')
             ->hourly()
             ->withoutOverlapping();

    // Opcional (sólo si CHATBOT_DASHBOARD_ENABLED=true): housekeeping del
    // Personal Dashboard. Sin flags el comando sale con error — siempre
    // declarar explícitamente qué prunea. Ver docs/dashboard.md §9.3.
    $schedule->command('chatbot:dashboards:prune', [
                 '--source-missing', '--stale', '--empty-dashboards', '--force',
             ])
             ->weekly()
             ->withoutOverlapping();

    // (Opcional) hard-delete mensual de filas soft-deleted hace > 30 días:
    // $schedule->command('chatbot:dashboards:prune', [
    //              '--purge-soft-deleted', '--force',
    //          ])->monthly()->withoutOverlapping();
}
```

**`chatbot:cleanup-actions`**: marca como `expired` cualquier
`pending_action` con `expires_at < now()` — soft, **no DELETE** (preserva
auditoría y evita ruido en la sección `## Pending actions` del prompt).
Detalle en [`confirmation-flow.md`](confirmation-flow.es.md).

**`chatbot:dashboards:prune`** (v2.0): soft-delete de widgets
inservibles (tool desapareció, refresh muy antiguo, dashboards vacíos)
y opcionalmente hard-delete de lo ya soft-deleted. Cuatro modos opt-in;
dry-run por defecto, `--force` ejecuta. Sin flags el comando sale con
error. Thresholds default 30/90/180/30 días, configurables vía
`chatbot.dashboard.prune.*` u override CLI. Documentación completa de
flags + receta de scheduler en [`dashboard.md`](dashboard.es.md).

**Frecuencia recomendada**:

- **`chatbot:cleanup-actions`**: `hourly` por defecto;
  `everyTenMinutes` si tienes muchos `confirm` (TTL 10 min) y notas que
  los expirados tardan en desaparecer del prompt.
- **`chatbot:dashboards:prune`**: `weekly` cubre la mayoría de los
  hosts; los thresholds default (30/90/180 días) son conservadores. Si
  el host tiene muchísimos widgets pinneados y un `ToolRegistry` que
  cambia con frecuencia, considere `daily` con `--source-missing` aislado.

### 7.5 Concurrency (replay del Personal Dashboard)

El bulk refresh del dashboard (`POST /chatbot/dashboards/{slug}/refresh`,
v2.0) re-ejecuta los tools de cada widget con `Concurrency::run()`,
chunkeado al cap `chatbot.dashboard.replay.concurrency` (default 8). El
driver lo decide el host vía `config/concurrency.php`:

```php
// config/concurrency.php — publícalo con:
//   php artisan config:publish concurrency
return [
    // 'sync' (seguro en cualquier entorno), 'process' o 'fork'.
    'default' => env('CONCURRENCY_DRIVER', 'sync'),
];
```

| Driver | Cuándo | Notas |
|--------|--------|-------|
| `sync` | **Default seguro.** Windows/WAMP, shared hosting, contenedores sin `pcntl`. | Ejecuta los replays secuencialmente en el mismo proceso. Sin paralelismo, pero sin sorpresas. Para dashboards con pocos widgets el coste secuencial es imperceptible. |
| `process` | Hosts con PHP-FPM estándar y capacidad de spawnear subprocesos `artisan`. | Paraleliza de verdad. Cada task se serializa y corre en un subproceso con un boot fresco del framework — el host debe garantizar que sus tools se registran en el boot (service provider/config), no en runtime. |
| `fork` | Hosts con la extensión `pcntl` (CLI/Octane, no FPM web). | Paraleliza sin re-bootear; el más rápido donde está disponible. |

**Importante** — Laravel 11+ NO publica `config/concurrency.php` por
defecto, así que sin publicarlo `concurrency.default` cae al hardcoded
`process`. Si el host no tiene subprocess viable (típico en Windows/WAMP),
el bulk refresh fallará. Publica el config y fija `sync` salvo que tu
infra soporte `process`/`fork`.

Los tasks que el paquete pasa a `Concurrency::run()` son
serializable-friendly: closures `static` que NO capturan el grafo del
`ReplayService` (sólo el widget + el usuario), así que cualquier driver es
seguro desde v2.1.0. En v2.0.0 el task capturaba `$this` y agotaba la
memoria bajo `process`/`fork` — si vienes de v2.0.0 con el workaround
`config/concurrency.php` en `sync`, puedes mantenerlo o subir a
`process`/`fork` tras actualizar.

---

## 8. Hardening

### 8.1 CSP (Content Security Policy)

El widget vive en shadow DOM y no inyecta scripts inline. CSP mínima:

```
default-src 'self';
script-src  'self';
style-src   'self' 'unsafe-inline';   /* shadow DOM injecta <style> propios */
connect-src 'self';                   /* /chatbot/stream */
img-src     'self' data:;             /* avatars y blocks card con imágenes */
```

Si sirves el bundle desde CDN, añade `script-src 'self' https://cdn.tu-host.com`.

### 8.2 CSRF

`POST /chatbot/stream` y `POST /chatbot/actions/{id}/confirm` están en el grupo
`web` y requieren CSRF token. El widget lo lee automáticamente del meta tag
`<meta name="csrf-token" content="{{ csrf_token() }}">` que Laravel añade por
default.

Si tu app no incluye ese meta tag, añádelo al layout principal.

### 8.3 Sensible data redaction

El paquete **no** redacta automáticamente PII en logs ni en eventos
`ToolInvoked`. Si tus tools manejan datos sensibles (PII, salud, financieros),
implementa redaction en el listener:

```php
Event::listen(ToolInvoked::class, function ($event) {
    $args = collect($event->args)
        ->map(fn ($v, $k) => str_contains($k, 'email') ? '[REDACTED]' : $v)
        ->all();

    Log::channel('audit')->info('chatbot.tool', compact('args') + [...]);
});
```

### 8.4 Page context

El usuario puede manipular el `<meta name="chatbot:context">`. **Nunca**
dependas del page context para tomar decisiones de autorización en el backend
sin re-validar:

```php
// MAL — confía en algo que el cliente puede falsificar
$tenantId = $pageContext['tenant_id'];

// BIEN — valida que el usuario realmente tiene acceso a ese tenant
if (! $user->tenants()->where('id', $tenantId)->exists()) {
    return ToolResult::error('out_of_scope', 'No accesible.');
}
```

Si extiendes la cascada con datos del page context, mantén la misma disciplina.

---

## 9. Observabilidad

### 9.1 Logs

| Canal | Qué loguear | Para qué |
|---|---|---|
| `chatbot` o `audit` | `ToolInvoked` con user/tool/result/duration | trazabilidad legal/compliance |
| `default` | warnings del paquete (page_context overflow, scope no resuelto) | salud del paquete |
| `slow` | tools cuyo `duration > 5s` | optimización |

### 9.2 Métricas (Prometheus / StatsD)

Métricas útiles para alertar:

- `chatbot.stream.requests_total{provider,model}` — counter.
- `chatbot.stream.duration_seconds{provider,model}` — histogram.
- `chatbot.tool.invocations_total{tool,result}` — counter.
- `chatbot.tool.duration_seconds{tool}` — histogram.
- `chatbot.pending_actions{state}` — gauge (alimentado por un job que cuenta
  filas por status).

Engánchalo desde un listener de `ToolInvoked` que llame a `\Statsd::*` o
similar.

### 9.3 Trazas distribuidas

El stream SSE atraviesa varias capas (controller → ChatService → Prism →
provider). Si usas OpenTelemetry, instrumenta las dos llamadas externas:

- Llamada Prism → provider (HTTP outbound).
- Backend tools del host (cada `handle()` puede ser una span).

El paquete no instrumenta automáticamente; el host inyecta los span tags vía
listener de `ToolInvoked` y middlewares HTTP estándar.

---

## 10. Despliegue

### 10.1 Pasos canónicos en CI/CD

```bash
# Build
composer install --no-dev --optimize-autoloader
npm ci && npm run build       # sólo si compilas widget custom

# Deploy
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan vendor:publish --tag=chatbot-assets --force

# Restart workers
php artisan queue:restart    # si usas queues
sudo systemctl reload php-fpm
```

### 10.2 Post-deploy smoke test

```bash
# Provider activo
php artisan chatbot:test-connection

# Tools registradas
php artisan chatbot:tools:list
```

Si ambos pasan, los componentes críticos están sanos.

### 10.3 Rolling deployments

`/chatbot/stream` mantiene la conexión abierta minutos. Durante un rolling
deploy, los streams en vuelo se cortan al matar el worker viejo. El widget
reintenta con backoff — los usuarios ven un freeze de 1-2s y siguen.

Si tu SLA exige zero-disconnect, considera:

- Anunciar el deploy en el chat con un block de tipo `text` antes del corte
  ("Vamos a desplegar mejoras, conexión se reanudará en 30s").
- Drain mode: dejar de aceptar nuevas requests SSE pero servir los abiertos
  hasta que terminen.

### 10.4 Rollback

`rnkr69/lara-chatbot` sigue SemVer. Rollback de un release minor o patch:

```bash
composer require rnkr69/lara-chatbot:0.4.0 --no-update
composer update rnkr69/lara-chatbot --with-dependencies
php artisan migrate --rollback     # SOLO si la nueva versión añadió migraciones
php artisan vendor:publish --tag=chatbot-assets --force
```

> Nota: estamos en `0.x`, así que MINOR puede romper. Hacer pin exacto a una versión `0.4.N` concreta para producción y revisar el CHANGELOG antes de subir.

Las migraciones del paquete son aditivas; un rollback puede dejar columnas
extra que no estorban. Detalle de la política breaking change en `CHANGELOG.md`
sección "Versioning policy".

---

## 11. Costes

El coste dominante en producción es el **provider LLM**. Estimación:

| Provider · Modelo | Coste por turno típico (1k tokens prompt + 500 out) |
|---|---|
| Claude Sonnet 4.6 | ~$0.005 |
| Claude Opus 4.7 | ~$0.025 |
| OpenAI GPT-4.1 | ~$0.005 |
| Gemini 2.5 Pro | ~$0.003 |
| Groq Llama 3.3 70B | ~$0.0005 |
| Ollama local | $0 (compute propio) |

Para abaratar:

- **History truncation**: `chatbot.limits.history_messages = 20` recorta el
  histórico al LLM (los antiguos siguen en BD). Bájalo si los turnos no
  necesitan tanto contexto.
- **Cache**: Anthropic y OpenAI soportan prompt caching. Prism no lo expone aún
  uniformemente; si tu provider lo soporta, considera enviar el system prompt
  en una request inicial cacheable.
- **Modelo más pequeño para turnos triviales**: implementa una heurística
  pre-LLM (e.g. saludo simple → no llames al LLM, devuelve un canned
  response).

---

## 12. Checklist pre-producción

- [ ] Nginx/Apache configurado con `proxy_buffering off` para `/chatbot/stream`.
- [ ] PHP-FPM `output_buffering = Off`.
- [ ] CDN excluyendo `/chatbot/stream` del passthrough cacheable.
- [ ] `chatbot:cleanup-actions` programado en el scheduler.
- [ ] (Sólo dashboard v2.0) `chatbot:dashboards:prune` programado con los flags y la cadencia adecuada al volumen del host (ver §7 + [`dashboard.md`](dashboard.es.md)).
- [ ] `chatbot:test-connection` verde en el entorno destino.
- [ ] Rate limit ajustado al volumen esperado.
- [ ] Bundle del widget publicado en `public/` (o subido al CDN).
- [ ] Listener de `ToolInvoked` registrado para auditoría.
- [ ] CSP del host actualizada (al menos `style-src 'unsafe-inline'`).
- [ ] Migraciones aplicadas (`migrate --force`).
- [ ] Variables de entorno con API keys configuradas.
- [ ] Smoke test en staging: el widget aparece, responde, llama a una tool, la
      tool autoriza y devuelve datos correctos para el usuario logueado.
- [ ] Métricas de provider exportadas (al menos coste y latencia P95).

---

## 13. Referencias

- Configuración: `config/chatbot.php` (sección `limits` y `route`).
- Controller SSE: `src/Http/Controllers/ChatController.php`.
- Comando cleanup: `src/Console/Commands/CleanupActionsCommand.php`.
- Widget: [`WIDGET.md`](WIDGET.es.md).
- Distribución / matriz CI: [`distribution.md`](distribution.es.md).
- Troubleshooting runtime: [`troubleshooting.md`](troubleshooting.es.md).
