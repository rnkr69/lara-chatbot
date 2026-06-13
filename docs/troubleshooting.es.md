# Troubleshooting

*[English](troubleshooting.md) · Español*

> Síntomas → causas probables → fix. Ordenado de "más común" a "menos común".
> Si el síntoma exacto no aparece aquí, busca por código de error / clase de
> excepción en el repo (`grep -r "ScopeResolverNotConfigured"` etc.) — los
> mensajes del paquete son específicos por diseño ("falla ruidosamente").
>
> Cada entrada tiene un código (`L1`, `M3`, …) para que puedas referenciarlo en
> issues y otras docs.

---

## L · Conexión LLM

### L1 · `chatbot:test-connection` falla con timeout

**Síntoma**:
```
ConnectException: cURL error 28: Operation timed out after 30000 milliseconds
```

**Causa**: el provider responde lento o la red bloquea el outbound.

**Fix**:
1. Verifica que la API key es válida y no ha expirado.
2. Si estás detrás de proxy corporativo: configura `HTTP_PROXY`/`HTTPS_PROXY`
   en `.env`.
3. Sube el timeout en `config/services.php` para el cliente Prism:
   ```php
   'anthropic' => [
       'api_key' => env('ANTHROPIC_API_KEY'),
       'timeout' => 60,
   ],
   ```

### L2 · `LlmException: Provider returned 401 Unauthorized`

**Causa**: API key inválida, mal copiada (espacios, saltos de línea), o de un
proyecto distinto.

**Fix**:
- Re-lee la key desde el dashboard del provider y reemplázala.
- Verifica que no tenga espacios en `.env`: el `chatbot:install` preserva keys
  existentes; si añadiste manualmente `ANTHROPIC_API_KEY="sk-..."` con
  espacios alrededor del `=`, Laravel los conserva.
- Tras editar `.env`: `php artisan config:clear`.

### L3 · `LlmException: model not found`

**Causa**: el modelo configurado en `CHATBOT_MODEL` no existe en el provider,
o no está habilitado para tu cuenta (Claude Opus suele requerir tier pago).

**Fix**:
- Lista modelos disponibles del provider y ajusta `CHATBOT_MODEL`.
- Para Claude: `claude-sonnet-4-6`, `claude-haiku-4-5-20251001`,
  `claude-opus-4-7` son los IDs canónicos (cutoff 2026-01).

### L4 · LLM responde pero "habla en inglés" cuando esperabas español

**Causa**: el system prompt base no fija idioma. El LLM mimetiza el idioma del
último mensaje, pero a veces decide en inglés por defecto.

**Fix**: extiende el addendum del system prompt en
`resources/views/vendor/chatbot/system_prompt_addendum.blade.php`:

```blade
- Responde siempre en español (España).
- Si el usuario escribe en otro idioma, responde en ese idioma.
```

> El addendum se publica con `chatbot:install` (paso opt-in) o con
> `vendor:publish --tag=chatbot-prompts`.

---

## M · Widget en el navegador

### M1 · El widget aparece pero no responde

**Síntoma**: click en el FAB → panel se abre → escribes → spinner infinito o
cierre inmediato del stream.

**Causa probable** (en orden de frecuencia):

1. **CSRF token ausente**. El widget lee `<meta name="csrf-token">` del head;
   si no existe, manda la request sin token y Laravel devuelve 419.
2. **CDN buffer-eando el SSE**. Cloudflare/Fastly por defecto buffer-ean
   `text/event-stream` si no excluyes la ruta del cache.
3. **Nginx `proxy_buffering on;`** ignora el header `X-Accel-Buffering: no`.

**Fix**:
1. Añade `<meta name="csrf-token" content="{{ csrf_token() }}">` al `<head>`.
2. Excluye `/chatbot/stream*` del cache CDN. Detalle en
   [`deployment.es.md`](deployment.es.md).
3. Configura Nginx con `proxy_buffering off;` para esa ruta. Detalle en
   [`deployment.es.md`](deployment.es.md).

### M2 · El widget no aparece

**Síntoma**: no hay FAB en la esquina, ni errores en consola.

**Causas**:

1. El bundle no está en `public/vendor/chatbot/chatbot-widget.js`:
   ```bash
   php artisan vendor:publish --tag=chatbot-assets --force
   ```
2. El layout no incluye el snippet. Verifica que tienes:
   ```html
   <chatbot-widget data-endpoint="/chatbot/stream"></chatbot-widget>
   <script src="/vendor/chatbot/chatbot-widget.js" defer></script>
   ```
3. El layout sí incluye el snippet pero está antes del cierre `</head>` con
   `defer`. Cambia el orden: `<chatbot-widget>` justo antes de `</body>`,
   `<script>` también antes de `</body>` o con `defer`/`async`.

### M3 · Widget aparece pero el panel se ve "raro" / sin estilos

**Causa**: el shadow DOM normalmente está aislado, pero si tu host inyecta
estilos vía `* { …}` con `!important`, pueden filtrar. Más común: el host
elimina el `<style>` interno del paquete por una directiva CSP estricta.

**Fix**: añade `style-src 'unsafe-inline'` a tu CSP. El widget usa estilos
inline porque están scoped al shadow DOM (no son globales).

### M4 · "El widget se cierra solo a los segundos"

**Síntoma**: el panel se cierra y vuelves al FAB sin haber clickeado.

**Causa**: el host tiene un script que dispara `Chatbot.close()` o un
`window.dispatchEvent` que el widget interpreta como close.

**Fix**: en consola, antes de abrir el widget:
```javascript
window.addEventListener('chatbot:state-change', e => console.log(e.detail));
```
y reproduce el cierre. Ves qué evento o método lo dispara y revisa tu código.

### M5 · "Veo dos widgets duplicados"

**Causa**: el snippet `<chatbot-widget>` aparece dos veces en el DOM (typical
en Inertia + layout `app` que se aplica a todos los views).

**Fix**: el web component es idempotente sólo si `window.Chatbot` ya estaba
montado, pero la **etiqueta** sí se duplica. Asegúrate de incluirlo **una sola
vez** en tu layout más alto.

---

## H · Tools

### H1 · El LLM no usa una tool nueva

**Síntoma**: ejecutas `chatbot:tools:list`, ves la tool registrada, pero el
LLM no la invoca aunque preguntes algo que la requiere.

**Causa probable**: la `description()` no es lo suficientemente clara para
que el LLM decida invocarla.

**Fix**: el LLM elige tools por la descripción, no por el nombre. Ajusta:

```php
// Mal
public function description(): string { return 'Lista facturas.'; }

// Bien
public function description(): string
{
    return 'Lista las facturas del usuario actual con filtros opcionales por '
         . 'estado (paid|pending|cancelled) y un límite máximo de filas. '
         . 'Útil cuando el usuario pregunta por sus facturas, pendientes de '
         . 'pago, o quiere ver el historial reciente.';
}
```

> Reglas: incluye **cuándo** invocarla (ejemplos breves), no sólo qué hace.

### H2 · `ScopeResolverNotConfiguredException`

**Síntoma**:
```
Rnkr69\LaraChatbot\Authorization\Exceptions\ScopeResolverNotConfiguredException:
  ScopeResolver no soporta el scope Team.
```

**Causa**: la tool declara `defaultScope=Team`/`All` pero el host no
implementa `ScopeResolver` o lo dejó devolviendo `[]` (no implementado).

**Fix**: implementa el resolver (ver [`authorization.es.md`](authorization.es.md)):

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

### H3 · `MissingTenantResolverException` al boot

**Síntoma**: `php artisan serve` arranca con:
```
La tool 'list_event_attendees' declara tenantScope=true pero no hay
TenantResolver registrado.
```

**Fix**: implementa y registra el `TenantResolver`. Ver
[`authorization.es.md`](authorization.es.md).

### H4 · Backend tool con `confirmation=confirm` no se ejecuta

**Síntoma**: el LLM la llama pero el orquestador la ignora con un warning en
los logs:
```
Tool 'delete_record' declara confirmation=confirm; en v1 sólo se admite Auto
para backend tools. Filtrada del catálogo.
```

**Causa por diseño**: en v1 las backend tools sólo soportan `confirmation=auto`
(pausar/reanudar el stream a mitad de turno es v2).

**Fix**: declara una **frontend tool** con `confirmation=confirm` que reciba
los args y, una vez confirmada, dispare la backend tool. Detalle en
[`backend-tools.es.md`](backend-tools.es.md).

### H5 · La tool se ejecuta dos veces para una sola pregunta

**Síntoma**: `Log::audit` muestra dos llamadas a `list_invoices` para el mismo
turno.

**Causas**:

1. **`ChatService` agrupa tool calls del LLM**: si el LLM emite el mismo
   `tool_call` dos veces en streaming, `ChatService` los ejecuta por separado.
   No es un bug del paquete; es comportamiento del LLM.
2. **`max_steps` permite multi-turn**: `chatbot.limits.max_steps=5` permite al
   LLM iterar tool→LLM→tool. Si el LLM piensa que el primer resultado fue
   incompleto, vuelve a llamar.

**Fix**:
- Para idempotencia: tu `handle()` debe ser idempotente para los mismos args
  (mejor patrón que cazar dobles invocaciones).
- Si quieres prohibir re-invocación: aumenta la calidad de `description()`
  para que el LLM no se confunda. O baja `max_steps` a 2 / 3.

---

## A · Acciones de frontend

### A1 · `navigate` no funciona en SPA

**Síntoma**: el LLM emite `frontend_action` con `tool=navigate` pero la página
no cambia.

**Causa**: el widget detecta MPA por default y hace `location.assign(url)`. Si
tu app es Inertia/Livewire, necesitas registrar el navigator SPA:

**Fix**: en tu bundle JS (después de cargar el widget):

```javascript
window.Chatbot.registerNavigator((url) => {
    if (window.Inertia) {
        window.Inertia.visit(url, { preserveScroll: true });
        return true; // navegado por nosotros
    }
    return false; // delega al fallback (location.assign)
});
```

Alternativa: añade `<meta name="chatbot:runtime" content="spa">` al layout y el
widget aplica el detector heurístico (Inertia/Livewire/popstate). Detalle en
[`WIDGET.es.md`](WIDGET.es.md).

### A2 · `download_file` no descarga nada

**Síntoma**: el LLM emite `frontend_action` con `tool=download_file` y un id;
el browser no descarga.

**Causas**:

1. `chatbot.tools.download_file.allowed_disks` está vacío (default fail-secure
   — sin disks permitidos, todas las descargas fallan con
   `ToolResult::error('disk_not_allowed', ...)`).
2. La URL devuelta es `https://`/`http://` directa (rechazada — sólo
   firmadas locales).
3. La extensión Blade `<a download>` se bloquea por CSP `default-src 'self'`.

**Fix**:
1. En `config/chatbot.php`:
   ```php
   'tools' => [
       'download_file' => [
           'allowed_disks' => ['s3', 'local-private'],
           'max_expires_in' => 3600,
       ],
   ],
   ```
2. En tu tool concreto, devuelve un disk path firmable, no una URL absoluta.
3. CSP: añade `connect-src 'self' https://tu-cdn.com` si firmas URLs de S3.

### A3 · `fill_form` no encuentra los inputs

**Síntoma**: el LLM dice "rellené el formulario" pero los campos siguen vacíos.

**Causa probable**:
1. El `<form>` no tiene `id` ni `data-chatbot-form`, y la página tiene más de
   un `<form>` (el auto-discovery v1.1.1 elige el primer plausible, que puede
   no ser el que el LLM quería).
2. El LLM llama con `name="X"` pero ningún control del form expone ese name
   ni un alias `data-chatbot-field="X"`.
3. El widget se cargó antes que el form (Inertia/Livewire renders posteriores).

**Diagnóstico**: abre DevTools console al ejecutar la tool. Desde v1.0.1 la
primitiva loguea `console.warn` con `availableNames` (lista combinada de `name`
y `data-chatbot-field` presentes en el form) cuando un field no matchea.

**Fix recomendado** — marca el formulario y los inputs con aliases:

```html
<form data-chatbot-form="invoice_create">
    <input name="customer_id" data-chatbot-field="customer" />
    <input name="amount" data-chatbot-field="amount" type="number" />
</form>
```

El LLM ve `customer` y `amount` (no los names HTML internos) y el widget
busca primero por `[data-chatbot-field]`. Si tu host es Backpack o usa
forms Blade custom, automatiza esto con la directiva `@chatbotForm` o el
comando `chatbot:integrate-form <view>` (v1.1.1) — ver
[`integrations/custom-forms.es.md`](integrations/custom-forms.es.md).

---

## C · Confirmaciones

### C1 · El banner "Confirmar / Cancelar" no aparece

**Causa**: la tool declara `confirmation=auto` (default). Para que aparezca el
banner, la **frontend** tool debe declarar `confirmation=confirm` o `manual`.

**Fix**:
```php
public function confirmation(): ConfirmationLevel
{
    return ConfirmationLevel::Confirm;
}
```

### C2 · "Confirmar" da 409 en la 2ª llamada

**Síntoma**: 1ª llamada `{accept:true}` → 200 OK. 2ª llamada
`{accept:true,result:{done:true}}` → 409 Conflict.

**Causa**: el row ya está en estado `executed` (alguien lo procesó dos veces).

**Esperado**: el endpoint es idempotente — la 2ª llamada con el mismo accept
sobre un row terminal devuelve 409 con un body informativo, **no** repite la
acción. Tu cliente debería interpretar 409 sobre `executed` como "ya hecho".

**Fix de cliente**: si el banner del widget está usando un manejo custom,
trata `409` con `state=executed` igual que `200`.

### C3 · Pending actions se acumulan en BD

**Síntoma**: `chatbot_pending_actions` tiene miles de filas en estado `pending`
con `expires_at` muy en el pasado.

**Causa**: no estás corriendo `chatbot:cleanup-actions` en el scheduler.

**Fix**: ver [`deployment.es.md`](deployment.es.md).

---

## P · Page Context

### P1 · El bot no "ve" el contexto de la página actual

**Síntoma**: el usuario pregunta "¿cuáles son las facturas en pantalla?" y el
bot responde como si no supiera nada de la pantalla.

**Causa**:

1. No has añadido `<meta name="chatbot:context">` al layout/view.
2. La meta tag tiene JSON inválido (escape de comillas mal).
3. El widget está en SPA mode pero no escucha eventos `inertia:navigate`.

**Fix**:
1. Añade en la view:
   ```blade
   <meta name="chatbot:context" content='@json([
       "route" => "invoices.index",
       "filters" => ["status" => "unpaid"],
   ])'>
   ```
2. Verifica el JSON en consola: `JSON.parse(document.querySelector('meta[name="chatbot:context"]').content)`.
3. SPA: el widget re-lee el meta tag tras cada `inertia:navigate`/`livewire:navigated`/`popstate`. Si no lo hace, registra en consola:
   ```javascript
   window.addEventListener('chatbot:context-changed', e => console.log(e.detail));
   ```
   y verifica que el evento se dispara.

### P2 · "El page context se trunca silenciosamente"

**Síntoma**: tu meta tag tiene 50 KB de datos pero el LLM sólo ve un esqueleto.

**Causa**: `chatbot.limits.page_context_kb` (default 16 KB) se excede; el
sanitizer descarta el contexto entero.

**Fix**:
- Sube el límite si tu use-case lo necesita: `'page_context_kb' => 64`.
- Mejor: reduce lo que envías al meta tag. El bot no necesita la lista completa
  de filas, sólo el shape de filtros + IDs visibles.

---

## E · Errores del paquete (boot)

### E1 · `RouteNotDefinedException: chatbot.stream`

**Causa**: el provider no se registró. Posibles motivos:

1. `composer require` no ha refrescado el autoloader.
2. Auto-discovery deshabilitado en tu `composer.json`:
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

### E2 · `ConfigurationException: Provider X no soportado por Prism`

**Causa**: `CHATBOT_PROVIDER` apunta a un nombre que Prism no reconoce. Prism
sigue una convención: `anthropic`, `openai`, `groq`, `gemini`, `mistral`,
`ollama`. No hay `google` (es `gemini`), ni `chatgpt` (es `openai`).

**Fix**: usa los nombres canónicos de Prism. Detalle en
[`getting-started.es.md`](getting-started.es.md).

### E3 · `MigrationException: table chatbot_conversations already exists`

**Causa**: ejecutaste `chatbot:install --force` y publicaste migraciones, pero
ya estaban aplicadas en una run anterior.

**Fix**:
```bash
# Opción A: rollback y re-correr
php artisan migrate:rollback --path=database/migrations/2026_05_08_000001_create_chatbot_conversations_table.php
php artisan migrate

# Opción B: marcar como ya corridas (si los datos ya están bien)
php artisan migrate --pretend
# Si todo OK, marca el batch manualmente en la tabla migrations.
```

---

## D · Debugging avanzado

### D1 · Activar log verbose del paquete

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

### D2 · Inspeccionar el system prompt real que se envió al LLM

El system prompt se compone vía `SystemPromptBuilder` (vista publishable +
addendum + `## Current page` + `## Pending actions`). Para verlo en limpio
durante un turno:

```php
// dd-debug temporal en SystemPromptBuilder::build()
\Log::channel('chatbot')->debug('system_prompt', ['body' => $body]);
```

O fuera del paquete, sin tocar core:

```php
$prompt = app(\Rnkr69\LaraChatbot\Llm\SystemPromptBuilder::class)
    ->build(auth()->user(), $tools = collect(), $pageContext = []);

dd($prompt);
```

### D3 · Reproducir un fallo en tests

Cuando un usuario reporta un bug, el patrón más rápido es:

```php
// tests/Feature/RegressionTest.php (en el host)
test('reproduce bug X', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/chatbot/stream', [
        'message' => 'el mensaje exacto del usuario',
        'page_context' => ['route' => 'invoices.show', 'invoice_id' => 123],
    ]);

    $response->assertOk();
    // ...
});
```

Mockea Prism con `Prism::fake()` para evitar la llamada real al LLM. Detalle
en [`testing.es.md`](testing.es.md).

---

## F · FAQ

**Q: ¿Funciona con Laravel 10?**

No en v1. Si tu app está en L10, planifica el upgrade — `prism-php/prism` también
requiere ^11.

**Q: ¿Funciona con Octane / FrankenPHP?**

Sí. El SSE bajo Swoole funciona desde 4.x. Bajo FrankenPHP, asegúrate de
deshabilitar buffering y subir timeouts. Detalle en
[`deployment.es.md`](deployment.es.md).

**Q: ¿Puedo usar mi propio LLM custom (no soportado por Prism)?**

Sí, pero requiere extender `LlmGateway`. No es una vía soportada en v1; valora
si tu LLM puede exponerse vía OpenAI-compatible (LM Studio, vLLM, etc.) — Prism
soporta endpoints OpenAI-compatibles.

**Q: ¿Cómo desactivo completamente el chatbot en un entorno?**

```env
# Quita el snippet <chatbot-widget> del layout, o
CHATBOT_ENABLED=false
```

Y en `routes/web.php`:
```php
if (config('chatbot.enabled', true)) {
    Route::loadFrom(base_path('vendor/rnkr69/lara-chatbot/routes/chatbot.php'));
}
```

**Q: ¿Puedo cambiar la ruta `/chatbot` por otra?**

Sí, en `config/chatbot.php`:
```php
'route' => [
    'prefix' => 'asistente',
    'middleware' => ['web', 'auth'],
    'as' => 'asistente.',
],
```

Tras esto, el endpoint pasa a `/asistente/stream`. Recuerda actualizar el
atributo `data-endpoint` del widget.

**Q: ¿Cómo accedo al `Authenticatable` actual desde una tool?**

El `ToolContext` que recibe `handle()` lo expone:
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

## Soporte

- Para bugs reproducibles: incluye versión del paquete (`composer show rnkr69/lara-chatbot`),
  versión de Laravel, provider del LLM, fragmento del log con `LOG_LEVEL=debug`,
  y un snippet de código que reproduzca el problema.
- Para preguntas genéricas: lee primero `getting-started.es.md` + `authorization.es.md`
  + esta página antes de preguntar.
