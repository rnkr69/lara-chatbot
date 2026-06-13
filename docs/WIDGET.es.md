# `<chatbot-widget>` — Web Component

*[English](WIDGET.md) · Español*

Bundle distribuido por el paquete que monta el chatbot en cualquier página Laravel
(o página HTML estática) sin dependencias en runtime.

- **Entry**: `public-build/chatbot-widget.js` (ES module).
- **Tamaño**: ~7 KB gzip (cap: 80 KB).
- **Compatibilidad**: navegadores con soporte ES2020 + Custom Elements v1
  (Chrome ≥80, Firefox ≥75, Safari ≥13.1, Edge ≥80).

## Instalación rápida

1. Construye el bundle (sólo cuando trabajas con código fuente; el host no
   necesita Node si consume el paquete vía Composer y publica los assets):

   ```bash
   npm install
   npm run build
   ```

2. Publica el asset en el host:

   ```bash
   php artisan vendor:publish --tag=chatbot-assets
   ```

3. Incluye el script en tu layout Blade:

   ```blade
   <chatbot-widget
       data-endpoint="{{ route('chatbot.stream') }}"
       data-position="right"
   ></chatbot-widget>

   @auth
       <meta name="csrf-token" content="{{ csrf_token() }}">
   @endauth
   <script type="module" src="{{ asset('vendor/chatbot/chatbot-widget.js') }}"></script>
   ```

## Atributos del custom element

| Atributo | Valores | Default | Descripción |
|---|---|---|---|
| `data-endpoint` | string (URL) | _requerido_ | Endpoint POST (`/chatbot/stream`). |
| `data-conversation-id` | string\|number | `null` | Id de conversación a reanudar. Si está vacío, el backend crea una nueva. |
| `data-conversations-endpoint` | string (URL) | derivado de `data-endpoint` | URL base del listado/CRUD de conversaciones (`chatbot.conversations.index`). Necesario para rehidratar el historial al re-montar el widget tras una navegación MPA. Si está vacío y `data-endpoint` termina en `/stream`, se deriva sustituyendo `/stream` por `/conversations` (patrón canónico del paquete). Declárelo explícitamente si tus rutas no siguen ese patrón. |
| `data-position` | `left` \| `right` | `right` | Lado del launcher fab. |
| `data-default-open` | `true` \| `false` | `false` | Si abre el panel al cargar. |
| `data-theme` | `auto` \| `light` \| `dark` | `auto` | Modo de color del widget. `light`/`dark` lo fuerzan ignorando contexto. `auto` resuelve en este orden: (1) `<html data-bs-theme>` del host si está presente — integración canónica con Bootstrap 5 / Tabler / Backpack-Tabler / AdminLTE / Filament; (2) `prefers-color-scheme` del SO. En `auto`, el widget observa cambios en runtime de `<html data-bs-theme>` (y de la media query del SO) y se actualiza sin reload. |

El atributo interno `data-state` (gestionado por el componente) refleja la
máquina de 4 estados: `closed` · `minimized` · `open` · `fullscreen`. El CSS de
shadow DOM expone los selectores `:host([data-state="..."])` para que los hosts
con CSS publicado puedan ajustar layout fino.

`data-theme-effective` (también gestionado por el componente, valores `light`
| `dark`) refleja el modo de color resuelto tras aplicar la cascada de
`data-theme`. El CSS de shadow DOM expone selectores
`:host([data-theme-effective="dark"])` / `…="light"` que sobreescriben los
defaults y el bloque `@media (prefers-color-scheme: dark)`. No declares este
atributo a mano — el widget lo proyecta y lo sincroniza con el toggle del
host.

## API global `window.Chatbot`

El bundle instala una API global idempotente. Si el script se incluye dos veces
(error común con bundles del host), la segunda carga preserva la primera y los
registros no se pierden.

```js
window.Chatbot.open();                 // abre el panel
window.Chatbot.close();                // lo cierra
window.Chatbot.toggle();               // alterna abierto/cerrado

window.Chatbot.setPageContext({        // contexto que se manda al backend
    route: 'admin/users/show',         // en cada POST a /chatbot/stream
    user_id: 42,
});
window.Chatbot.clearPageContext();

window.Chatbot.setUser('eyJhbGciOi…'); // bearer token (Sanctum/JWT). null limpia.

// Sustituye o extiende una primitiva FE. Si el host registra `navigate`,
// el bundle delega en el handler del host en vez de usar la primitiva por defecto.
window.Chatbot.registerTool('navigate', (args, ctx) => {
    Inertia.visit(args.url);
});

// Renderer custom de bloques.
window.Chatbot.registerBlockRenderer('table', (data, host) => {
    const el = document.createElement('table');
    // … construir DOM con data
    return el;
});

// Adaptador de navegación pluggable. La primitiva `navigate` consulta
// primero el navigator registrado antes de caer a window.location.assign.
// Pierde frente a registerTool('navigate') — el override por tool gana siempre.
window.Chatbot.registerNavigator((url, opts) => {
    Inertia.visit(url, opts);
});
```

## Lectura SSE robusta

- POST con `fetch` + `ReadableStream` (no `EventSource`, que no soporta POST).
- Parser de frames `event: <name>\ndata: <json>\n\n` con normalización CRLF/CR.
- Catálogo cerrado de eventos (mismo que `Rnkr69\LaraChatbot\Sse\SseEvent`):
  `text` · `block` · `tool_call` · `tool_result` · `frontend_action` · `error` · `done`.
- Reconexión exponencial (1s → 2s → 4s → 8s → 16s, cap 30s, jitter 25%) hasta
  4 reintentos. `429 Too Many Requests` no reintenta y se reporta como error.
- `X-CSRF-TOKEN` se lee del meta tag `<meta name="csrf-token">` automáticamente.
- `setUser(token)` añade `Authorization: Bearer <token>` a cada request.
- Cancelación: el componente cancela el stream activo cuando se desconecta del
  DOM (`disconnectedCallback`).

## Markdown subset

El renderer integrado cubre lo mínimo para texto conversacional:

- `**bold**`, `*italic*`
- inline code `` `x` ``
- enlaces `[texto](url)` — sólo `http(s)`, `mailto:`, `tel:` y rutas relativas;
  `javascript:` y `data:` se imprimen como texto literal
- saltos de párrafo en línea en blanco; saltos simples se traducen a `<br>`

Todo input se escapa primero contra XSS (`<`, `>`, `&`, `"`, `'`). Hosts que
necesiten más (listas, headings, code blocks, tablas) deben publicar un
`registerBlockRenderer('text', …)` propio.

## Bloques tipados

Catálogo built-in del paquete:

- `text` — markdown subset (bold/italic/code/links).
- `actions` — botones con `label` + (`prompt` ó `tool` + `args`).
- `card` — título + subtítulo + descripción markdown + lista de campos + acciones inline.
- `table` — `rows[]` con `columns[]` opcionales (autoinfiere headers de la primera fila).
- `list` — `items[]` ordenados o no; cada ítem puede ser texto, prompt o tool.
- `chart` — placeholder; el host registra su renderer (Chart.js / ApexCharts / SVG propio).

El widget renderiza un block aplicando la **cascada de renderers** en este orden:

1. **`window.Chatbot.registerBlockRenderer(type, fn)`** — el JS renderer del host gana.
2. **`<template data-chatbot-block-template="<type>">`** — clona el template
   y rellena cada `[data-bind="path"]` con un lookup tipo lodash.get sobre `data`.
3. **Built-in** del paquete (los seis tipos de arriba).
4. **Placeholder** `[unsupported block: <type>]` si nada matchea.

Si un renderer del host lanza, el widget loguea `console.error` y cae al
siguiente paso de la cascada — un block roto no rompe el thread.

El backend tiene dos formas de emitir un block:

- **`RenderBlockTool`** (canónica): el LLM la invoca como frontend tool con
  `{type, data}`. `ChatService` la traduce a `frontend_action` con
  `tool=render_block`; el widget intercepta esa señal y la convierte en un
  block del assistant message. Sin cambios en el contrato SSE.
- **`SseEvent::block($type, $data)`** (servicios propios): cualquier consumidor
  del orquestador puede emitir el frame `event: block` directamente; el
  widget lo trata igual.

Doc completa con ejemplos: [`docs/block-renderers.es.md`](./block-renderers.es.md).

## Frontend actions

El widget procesa eventos `frontend_action` aplicando esta cascada:

1. Si `confirmation !== 'auto'`, encola la acción en una lista en memoria
   (`getPendingActions()` la expone) y muestra un toast informativo.
2. Si hay un handler registrado vía `window.Chatbot.registerTool(name, fn)`, se
   delega ahí. Permite al host **sobrescribir** primitivas core (típico:
   `navigate` → adaptador SPA).
3. Si no, ejecuta la primitiva interna correspondiente:
   - `navigate` → `window.location.assign(url)` (sólo same-origin; cross-origin
     se rehúsa silenciosamente; el host puede registrar su propio handler para
     navegación remota).
   - `toggle_visibility` → flip de `display:none` (acepta `visible: bool` para
     forzar).
   - `show_toast` → toast en el shadow DOM.
   - `download_file` → `<a href download>` con `download_url` y `expires_at`
     mergeados por `DownloadFileTool`. Sólo URLs `http(s)`; otras se
     rehúsan.
4. Si nada matchea, log warn `[chatbot] no handler registered for frontend tool "x"`.

Las primitivas `fill_form`, `open_modal`, `render_block` e `invoke_host_action`
caen al paso 1 cuando su `confirmation` no es `auto`. Hosts
que las quieran ejecutar en `auto` deben subclasear server-side
y ya tendrán handler registrado o caerán al log warn.

## SPA/MPA — detección, persistencia y navegación

El widget soporta tanto Multi-Page Apps (Blade clásico) como Single-Page Apps
(Inertia / Livewire SPA / pushState). El modo se detecta una vez en el primer
`connectedCallback` del custom element y se cachea por la vida del bundle.

**Heurística de detección** (en orden):

1. `<meta name="chatbot:mode" content="spa">` o `="mpa"` — wins (verdad sobre
   heurística; útil cuando la detección automática falla).
2. `window.Inertia` definido → SPA.
3. `window.Livewire` definido → SPA.
4. Default → MPA.

**Persistencia (`sessionStorage`)**

Clave canónica `chatbot:state:v1`. Shape:

```json
{ "conversationId": 42, "isOpen": true, "draft": "half-typed message" }
```

Se guarda con debounce 250ms tras cada cambio (input del textarea, transición
de estado del widget, mutación de `data-conversation-id`). Se rehidrata al
montar el custom element — `data-default-open` sólo se aplica si NO hay estado
persistido. En MPA cada page load reconstruye el widget; en SPA el widget no se
desmonta entre rutas, así que la persistencia sólo cobra utilidad en hard
reloads del shell.

Los errores de `sessionStorage` (modo privado, quota lleno, sandbox) se silencian
— la persistencia es best-effort y nunca rompe la UX.

**Adaptadores de navegación**

La primitiva interna `navigate` aplica esta cascada cuando el LLM emite un
`frontend_action { tool: "navigate" }`:

1. `registerTool('navigate', fn)` registrado por el host → gana siempre.
2. `registerNavigator(fn)` registrado por el host → reemplaza a la primitiva por
   defecto sin tocar otros tools.
3. Detección automática al momento de la llamada:
   - `window.Inertia.visit(url, opts)` si Inertia está presente.
   - `window.Livewire.navigate(url, opts)` si Livewire está presente.
   - `window.location.assign(url)` (MPA) en otro caso.

URLs cross-origin se rehúsan en silencio (defensa contra LLM mal-prompteado).
Para navegar a otro dominio, el host registra su propio handler vía
`registerTool('navigate', …)`.

**Cancelación de stream en navegación SPA**

En modo SPA el widget escucha `inertia:navigate`, `livewire:navigated` y
`popstate` y aborta el `streamPost` activo si lo hay. Justificación: una
respuesta a medio renderizar contra una ruta que ya no es la activa produciría
UI inconsistente. La conversación NO se pierde — el `conversationId` permanece
y el siguiente turno reanuda contra el backend.

## Page context

Cada POST a `/chatbot/stream` incluye:

- `message`: string del usuario
- `conversation_id`: si existe (reanudar conversación)
- `page_context`: el contexto efectivo (meta tag inicial + merge superficial
  de cada `setPageContext({...})`)

El widget arranca leyendo `<meta name="chatbot:context">` (si está) y, en
modo SPA, re-lee el meta tag tras cada `inertia:navigate`/`livewire:navigated`/
`popstate`. Cualquier cambio (programático o por nav) emite el evento
`chatbot:context-changed` en `window` con el contexto efectivo en `event.detail`.

```js
window.addEventListener('chatbot:context-changed', (e) => {
  console.log('chatbot context now:', e.detail);
});
```

El backend sanea el JSON tipo a tipo (sólo strings/números/booleans/arrays
sobreviven; closures, objects, recursos y nulls se descartan) y, si tras
sanear todavía excede `chatbot.limits.page_context_kb`, se descarta
binariamente (degradación silenciosa, no 422).

Receta completa, sanitización detallada, hook SPA y guía Backpack opt-in:
ver [`docs/page-context.es.md`](page-context.es.md).

## Smoke offline

Un fixture en `tests/js/fixtures/smoke.html` carga el bundle y stubea `fetch`
para emitir un stream canned. Útil para validar el comportamiento sin un
backend levantado:

```bash
npm run build
python3 -m http.server --directory tests/js/fixtures 4173
# abrir http://localhost:4173/smoke.html
```

## Tests

Vitest + jsdom. La suite cubre máquina de estados, parser SSE (frames /
reconexión / abort / CSRF / bearer), markdown subset, registries de la API
global, primitivas FE (auto + queued) y renderers de bloques.

```bash
npm test           # vitest, corrida única
npm run test:watch # vitest watch mode
npm run typecheck  # tsc --noEmit estricto
npm run test:e2e   # Playwright (2 escenarios MPA + SPA)
npm run build:check # bundle size guard (cap 80 KB gzip; actual: 8.00 KB)
```

Los E2E corren contra fixtures estáticas servidas por
`scripts/e2e-server.mjs` con `fetch` mockeado — sin Laravel, sin Vite, sin
DB. Mismo patrón que el smoke fixture pero ejecutado en chromium real.

## Limitaciones conocidas

- `fill_form`, `open_modal`, `render_block`, `invoke_host_action` quedan
  encoladas en `confirm`/`manual`.
- Sin renderers para `card`/`table`/`list`/`chart` built-in completos.
- Markdown subset: sin headings, listas ni code blocks. Override vía
  `registerBlockRenderer('text', …)` si hace falta.
