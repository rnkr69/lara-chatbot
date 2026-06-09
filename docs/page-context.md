# Page Context API

> El **page context** es el conjunto de metadatos que el host pasa al
> chatbot para que el LLM sepa qué pantalla está viendo el usuario.
> Implementado en E14 (ROADMAP §5/E14).

El paquete soporta tres canales para declarar el contexto: el **meta tag
declarativo**, la **API imperativa** del widget, y la **integración
opt-in con Backpack** para hosts que usen ese admin. Cualquier cambio
emite un evento global `chatbot:context-changed` que las integraciones
pueden escuchar.

---

## 1. Meta tag declarativo

La forma más simple. En el `<head>` de la página (típicamente desde el
layout Blade del host):

```html
<meta name="chatbot:context"
      content='{"route":"invoices.index","filters":{"status":"open"}}'>
```

El widget la lee al boot (`connectedCallback`) y, en modo SPA, también
en cada navegación detectada. El JSON debe empezar por `{` (un objeto
top-level); cualquier otra cosa se ignora silenciosamente.

---

## 2. API imperativa (`window.Chatbot`)

Para SPAs o pantallas dinámicas que cambian el contexto sin recargar:

```js
// Sustituye o añade claves; merge superficial.
window.Chatbot.setPageContext({
  route: 'invoices.show',
  invoice_id: 999,
});

// Borra todo el contexto efectivo.
window.Chatbot.clearPageContext();
```

`setPageContext()` realiza un **merge superficial** (top-level): claves
nuevas se añaden, las existentes se sobrescriben, y las que no aparecen
en el argumento se preservan. Conforme al ROADMAP §3.2.

```js
window.Chatbot.setPageContext({ route: '/orders', tenant: 7 });
window.Chatbot.setPageContext({ tenant: 9, locale: 'es' });
// Estado efectivo: { route: '/orders', tenant: 9, locale: 'es' }
```

---

## 3. Hook SPA y evento `chatbot:context-changed`

Cualquier cambio en el contexto efectivo dispara un `CustomEvent` en
`window` con el contexto en `event.detail`:

```js
window.addEventListener('chatbot:context-changed', (e) => {
  console.log('Page context is now:', e.detail);
});
```

El evento se emite en **dos** ocasiones:

1. Cada llamada a `setPageContext()` o `clearPageContext()`.
2. En modo SPA, tras cada navegación detectada (`inertia:navigate`,
   `livewire:navigated`, `popstate`): el widget re-lee el meta tag y,
   si su contenido cambió, llama internamente a `setPageContext()` —
   que a su vez emite el evento.

El widget también aborta el stream activo en cada navegación SPA para
evitar respuestas a medio renderizar contra una ruta vieja (heredado de
E13).

> **Nota MPA**: en modo MPA cada page load reinicia el ciclo. El meta
> tag se lee al `connectedCallback` y el evento se emite una sola vez
> por carga.

---

## 4. Sanitización backend

El controller `POST /chatbot/stream` aplica dos pasadas sobre el campo
`page_context` del request:

### 4.1 Tipo a tipo (D13 — `PageContextSanitizer`)

Sólo sobreviven:

| Tipo PHP | ¿Sobrevive? |
|---|:-:|
| `string` (incluido HTML opaco) | ✅ |
| `int` | ✅ |
| `float` finito | ✅ |
| `bool` | ✅ |
| `array` (asociativo o lista) cuyos elementos sobrevivan a su vez | ✅ |
| `null` | ❌ se descarta |
| `object` (incluido `Closure`) | ❌ se descarta |
| `resource` | ❌ se descarta |
| `NaN` / `±INF` | ❌ se descarta |

Las claves de un array asociativo se coercen a `string`; las listas
mantienen claves enteras consecutivas (los huecos se re-indexan).

La profundidad máxima por defecto es 8 niveles (configurable por el
host overrideando `PageContextSanitizer::sanitize($raw, $maxDepth)`).
Niveles más profundos se podan.

### 4.2 Truncado binario (D11, fallback)

Si tras sanear el JSON resultante todavía excede
`chatbot.limits.page_context_kb` (default **16 KB**), se descarta entero
(se sustituye por `[]`) y se loguea `Log::info`. **No** se devuelve 422
— el turno continúa sin contexto. La razón: prefiere degradar a romper
la UX cuando el host envía un contexto demasiado generoso por accidente.

### 4.3 Inyección en el system prompt

El `SystemPromptBuilder` añade programáticamente la sección
`## Current page` con el JSON saneado al final del prompt base, antes
de la instrucción de idioma. La sección NO vive en la vista publishable
(`resources/views/system_prompt.blade.php`) porque el host puede
sobrescribirla — y el contrato del paquete debe sobrevivir al override.

```text
You are a helpful assistant integrated into a Laravel application…

## Current page
The user is currently looking at the following page (host-declared, sanitized):
```json
{
    "route": "invoices.show",
    "invoice_id": 999
}
```

Always respond in Spanish unless the user explicitly requests another language.
```

---

## 5. Integración Backpack (opt-in)

Si el host usa [`backpack/crud`](https://backpackforlaravel.com), el
paquete expone una directive Blade y un provider que pueblan el meta
tag con datos del `CrudPanel` actual.

### 5.1 Activación

No hay nada que instalar. Si la clase
`Backpack\CRUD\app\Library\CrudPanel\CrudPanel` existe en el runtime,
el `ChatbotServiceProvider` registra automáticamente:

- el singleton `Rnkr69\LaraChatbot\Integrations\Backpack\BackpackPageContextProvider`,
- la directive Blade `@chatbotBackpackContext`.

Si Backpack no está instalado, ambos son no-op silencioso (el host
puede colocar la directive en su layout sin que rompa páginas no-admin).

### 5.2 Uso desde Blade

```blade
{{-- En tu layout admin (ej. resources/views/admin/layout.blade.php) --}}
<head>
    @chatbotBackpackContext
    {{-- ...resto del head, incluido el script del widget --}}
</head>
```

La directive renderiza, server-side, un `<meta name="chatbot:context">`
con el shape:

```json
{
  "crud": {
    "entity": "App\\Models\\Invoice",
    "action": "list",
    "filters": { "status": "open" },
    "selected_ids": [11, 22, 33]
  }
}
```

Campos vacíos se omiten para mantener el meta tag compacto. Si el panel
no está resuelto (página no-admin, error en boot, etc.) la directive
emite cadena vacía.

### 5.3 Conveciones recomendadas

Para que tools del host puedan reaccionar a contexto Backpack, se
recomienda anotar los grids y filas con atributos `data-chatbot-*` en
las vistas Blade del CRUD:

```blade
<table data-chatbot-target="crud-grid">
    @foreach($entries as $entry)
        <tr data-chatbot-context='{"id":{{ $entry->id }}}'>
            {{-- columnas --}}
        </tr>
    @endforeach
</table>
```

La guía completa con un ejemplo end-to-end (grid → bot ofrece acción
bulk → bot dispara FE tool sobre los seleccionados) vivirá en
`docs/integrations/backpack.md` (gap parte 2, **diferida a E21**).

---

## 6. Tests

| Suite | Cobertura |
|---|---|
| Pest unit `tests/Unit/Services/PageContextSanitizerTest.php` | tipos preservados/dropeados, recursión, profundidad, listas re-indexadas |
| Pest feature `tests/Feature/Http/ChatControllerStreamTest.php` | sanitizer en el pipeline + truncado binario fallback |
| Pest feature `tests/Feature/Services/ChatServiceTest.php` | DoD ROADMAP: cambio de page_context entre dos turnos cambia el system prompt |
| Pest feature `tests/Feature/Integrations/BackpackProviderShapeTest.php` | provider con CrudPanel mock (entity/action/filters/selected_ids) |
| Pest feature `tests/Feature/Integrations/BackpackIntegrationTest.php` | directive `@chatbotBackpackContext` y degradación sin Backpack |
| Vitest `tests/js/page-context.test.ts` | lectura del meta tag y dispatch del evento |
| Vitest `tests/js/api.test.ts` | merge superficial + emisión `chatbot:context-changed` en set/clear |
| Vitest `tests/js/widget.test.ts` | seed inicial desde meta tag + re-lectura en `inertia:navigate` |

---

## 7. Page context en pin/replay (v2.0)

A partir de v2.0 ([Personal Dashboard](dashboard.md)), una tool puede ser
`pinnable` y por tanto re-ejecutarse desde `/chatbot/dashboard`. El replay
ocurre en una página que **no tiene `page_context` propio** (el dashboard
es agnóstico al contexto de la página donde se hizo el pin). Sin
intervención, las tools que dependen del `page_context` devolverían
resultados genéricos o vacíos al refresh.

v2.0 resuelve esto en tres pasos:

### 7.1 Declarar las claves sensibles al contexto

La tool declara qué claves de `page_context` necesita para producir
resultados correctos:

```php
public function pageContextKeys(): array
{
    return ['tenant_id', 'team_id'];
}
```

Default `[]` — tools que no dependen del contexto no necesitan override.

### 7.2 Estampar en el block al chat

El orquestador SSE filtra el `page_context` activo a esas claves cuando
estampa el `source` del block:

```jsonc
{
  "type": "kpi",
  "data": { /* ... */ },
  "source": {
    "tool": "sales_this_month",
    "args": {},
    "page_context_keys": ["tenant_id", "team_id"]
  },
  "pinnable": true
}
```

### 7.3 Capturar al pin, aplicar al replay

Cuando el usuario hace click en 📌, el endpoint
`POST /chatbot/dashboards/{slug}/widgets` recibe el `page_context` íntegro
del cliente y:

1. Aplica el `PageContextSanitizer` (drop closures/objects/etc.).
2. **Filtra a las claves declaradas en `source.page_context_keys`**.
3. Aplica el cap binario `chatbot.limits.page_context_kb` — si excede tras
   filtrar, se descarta entero con un `Log::info` (preferimos perder
   contexto a romper el pin).
4. Persiste el subset filtrado en `source.page_context_snapshot` en
   `chatbot_dashboard_widgets`.

Al replay, el `ReplayService` (ver
[`dashboard.md` §7](dashboard.md#7-replay-engine)) construye un `ToolContext`
con el snapshot guardado, así que la tool ve exactamente el subset que
estaba presente cuando se pineó.

### 7.4 Qué pasa cuando faltan keys o el snapshot caduca

- **Claves ausentes en el snapshot al replay**: la tool recibe un
  `page_context` parcial; si su `handle()` requiere claves específicas y
  no hay degradación posible, debe devolver `ToolResult::error('validation', …)`
  → el replay marca el widget con `last_refresh_status='error'` y conserva
  el snapshot anterior.
- **Resolver de tenant ya no encaja**: si el `TenantResolver` ya no acepta
  el `tenant_id` snapshot (porque el usuario perdió acceso o la entidad
  fue eliminada), la cascada de autorización devuelve unauthorized y el
  status es `unauthorized` — snapshot anterior preservado.

La razón estricta del filtrado por `pageContextKeys()` es evitar persistir
claves sensibles que el author no se diseñó para que viajaran al
dashboard. **Si una tool no declara `pageContextKeys()`, su
`page_context_snapshot` queda vacío** — equivale a re-ejecutar sin
contexto. Tools que dependen del contexto **deben** declarar las claves o
no funcionarán bien tras el pin.
