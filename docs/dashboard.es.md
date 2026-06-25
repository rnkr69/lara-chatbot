# Personal Dashboard

*[English](dashboard.md) · Español*

> Documentación canónica del Personal Dashboard. Si llegas con preguntas
> sobre qué tablas introduce, cómo activar el pin en una tool, o cómo
> funciona el replay — este es el sitio.
>
> Cross-refs: el modelo de bloques sigue documentado en
> [`block-renderers.es.md`](block-renderers.es.md); la cascada de autorización en
> [`authorization.es.md`](authorization.es.md); el contrato de backend tools en
> [`backend-tools.es.md`](backend-tools.es.md); el `page_context` en
> [`page-context.es.md`](page-context.es.md). Esta guía referencia esos documentos
> en vez de duplicar lo que ya está allí.

---

## 1. Qué es

Hasta v1, el chatbot renderizaba **tablas, charts y KPIs como bloques
tipados efímeros**: viven en un mensaje del chat, scrollean fuera de vista,
y para volver a verlos el usuario tiene que repetir la pregunta. No hay
forma de "fijar" un resultado útil.

v2.0 introduce un **dashboard personal** donde el usuario puede:

1. **Pinear** cualquier bloque relevante que aparezca en el chat (📌).
2. **Colocar y redimensionar** esos elementos en un grid drag-and-drop
   (12 columnas, gridstack.js).
3. **Tener varios dashboards nombrados** ("Operaciones", "Ejecutivo",
   "Mis facturas"…).
4. **Volver al dashboard** y ver los datos **frescos y actualizados**
   automáticamente, no el snapshot del día que lo creó.

El punto duro técnico es la **frescura**: los bloques v1 no llevan
metadatos sobre qué tool los produjo, así que no se pueden "rejugar".
v2.0 extiende el contrato de bloques (`BlockPayload` gana `id`, `source`
y `pinnable`) y construye un **motor de replay** que respeta la misma
cascada de autorización del chat (`permission → scope → tenant → ownership`).

**Outcome esperado**: usuario pulsa 📌 en una tabla del chat → llega a su
dashboard → al volver al día siguiente ve los mismos números pero
actualizados, sin tener que volver a pedirlos.

### Lo que v2.0 NO introduce

- ❌ Sharing de dashboards entre usuarios o tenants — posible v2.1.
- ❌ Polling automático o refresh "live" via SSE permanente.
- ❌ Dashboard como Web Component embebible en páginas del host — posible v2.1.
- ❌ Alertas / thresholds sobre KPIs.
- ❌ Export del dashboard a PDF/imagen.

---

## 2. Flujo end-to-end

```mermaid
flowchart LR
    U[Usuario] --> C[Widget chat]
    C -->|pregunta| S[ChatService SSE]
    S --> T[BackendTool::handle]
    T --> S
    S -->|block id+source+pinnable| C
    U -.->|click 📌| C
    C -->|POST /chatbot/dashboards/.../widgets| API[ApiDashboardWidgetController]
    API --> DB[(chatbot_dashboard_widgets)]
    U -->|abre /chatbot/dashboard| D[DashboardController]
    D --> JS[chatbot-dashboard.js]
    JS -->|GET /chatbot/dashboards/{slug}| API
    API --> DB
    JS -->|POST .../refresh SSE| API
    API --> R[ReplayService]
    R --> T2[BackendTool::execute fresco]
    T2 --> R
    R -->|snapshot + status| API
    API -->|SSE widget_refreshed| JS
    JS -->|update DOM| U
```

**Anatomía del frame**: cuando el LLM emite un bloque, el orquestador SSE
estampa tres campos extra antes de mandarlo al cliente:

```jsonc
{
  "type": "table",
  "data": { "rows": [/* … */] },
  "id": "b-9f8e7d6c…",          // UUID generado server-side
  "source": {
    "tool": "list_my_invoices",
    "args": { "limit": 20 },
    "page_context_keys": ["tenant_id", "team_id"]
  },
  "pinnable": true                // sólo si tool->pinnable() = true
}
```

El **tool author NO toca `id` ni `source`**. El orquestador los inyecta
automáticamente al construir cada bloque. El tool sólo declara si es
pinnable (vía método PHP, ver §4).

---

## 3. Configurar `chatbot.dashboard.*`

Toda la sección vive bajo `config/chatbot.php`. Después de un
`composer update rnkr69/lara-chatbot && php artisan vendor:publish --tag=chatbot-config --force`
encontrarás:

```php
'dashboard' => [
    'enabled'                   => env('CHATBOT_DASHBOARD_ENABLED', true),
    'max_dashboards_per_user'   => 20,
    'max_widgets_per_dashboard' => 50,
    'snapshot_max_bytes'        => 256 * 1024,
    'replay' => [
        'driver'                         => env('CHATBOT_REPLAY_DRIVER', 'sync'),
        'concurrency'                    => 8,
        'timeout_seconds'                => 15,
        'rate_limit_per_user_per_minute' => 60,
    ],
    'chart_renderer'         => 'chartjs',
    'default_refresh_policy' => 'on_open',
    'layout'                 => env('CHATBOT_DASHBOARD_LAYOUT'),
    'section'                => 'content',
    'mount_widget'           => env('CHATBOT_DASHBOARD_MOUNT_WIDGET', true),
    'back_url'               => env('CHATBOT_DASHBOARD_BACK_URL'),
    'asset_path'             => 'vendor/chatbot/chatbot-dashboard.js',
],
```

| Clave | Default | Env var | Qué controla |
|---|---|---|---|
| `enabled` | `true` | `CHATBOT_DASHBOARD_ENABLED` | Si `false`, las rutas `/chatbot/dashboard*` no se registran y `pinnable()` se ignora — uso típico para evitar el bundle adicional en hosts que sólo quieren el chat v1. |
| `max_dashboards_per_user` | `20` | — | Tope superior; el endpoint de creación devuelve 422 si el usuario lo alcanza. |
| `max_widgets_per_dashboard` | `50` | — | Tope superior por dashboard; el endpoint de pin devuelve 422 si lo alcanza (el modal del widget mapea ese 422 a `error_dashboard_full`). |
| `snapshot_max_bytes` | `262144` (256 KB) | — | Si `json_encode(snapshot.data)` excede este cap, se persiste sólo `data.head` (primeros 20 elementos si era lista) + marker `truncated: true`. El replay sustituye con datos frescos al abrir. |
| `replay.driver` | `'sync'` | `CHATBOT_REPLAY_DRIVER` | Driver de `Illuminate\Support\Facades\Concurrency` para el bulk-replay. **`sync` (default)** corre los replays secuencialmente en el mismo proceso — sin serialización, sin subproceso, viable en cualquier host. El paquete elige este driver explícitamente; **no** hereda el `concurrency.default` del host (que en Laravel 11+ es `process` → `proc_open()`, no viable en Windows/WAMP, shared hosting sin `pcntl` ni contenedores sin `proc_open`). Un host con la infra adecuada lo sube a `process`/`fork`. |
| `replay.concurrency` | `8` | — | Máximo de tools paralelos en `replayBulk()`, chunkeado. Con el driver `sync` el cap sólo chunkea (no hay paralelismo real); con `process`/`fork` sí limita el paralelismo. |
| `replay.timeout_seconds` | `15` | — | Timeout por tool individual durante replay. Excedido → `last_refresh_status='error'` + snapshot anterior intacto. |
| `replay.rate_limit_per_user_per_minute` | `60` | — | Token-bucket por usuario sobre `POST .../refresh` y `POST .../widgets/{id}/refresh`. **No aplica al CRUD** (lista/crear/pin/borrar) — el coste real está en re-ejecutar tools, no en escribir filas. |
| `chart_renderer` | `'chartjs'` | — | Informativo desde 0.4.4 — Chart.js es el renderer CORE built-in del bloque `chart` en todos los bundles, así que esto ya no gatea el rendering (`'none'` no lo desactiva). Registra un override vía `window.Chatbot.registerBlockRenderer('chart', fn)` para usar otra librería. Ver §8. |
| `default_refresh_policy` | `'on_open'` | — | Política inicial al pinear: `on_open` re-ejecuta al abrir el dashboard, `manual` requiere click en ↻, `never` se queda en snapshot estático. El usuario puede cambiarla por widget vía PATCH. |
| `layout` | `null` | `CHATBOT_DASHBOARD_LAYOUT` | Si es string Y la vista existe, `chatbot::dashboard_layout` extiende ese layout (`@extends($layout) @section($section)`). Si null o no existe, se sirve `chatbot::dashboard` standalone. Mismo patrón que `chatbot.page.layout`. **Sin un `layout` configurado el dashboard corre standalone — sin la navegación del host (ver §5.2).** |
| `section` | `'content'` | `CHATBOT_DASHBOARD_SECTION` | Sección donde inyectar el contenido al extender el layout del host. |
| `mount_widget` | `true` | `CHATBOT_DASHBOARD_MOUNT_WIDGET` | En modo `layout`, monta el `<chatbot-widget>` flotante en la propia página del dashboard (vía `@push('after_scripts')`) para que el usuario pueda pinear **desde** el dashboard. Ponlo a `false` si el host inyecta el widget por su cuenta vía `extras_view`. Ver §5.2. |
| `back_url` | `null` | `CHATBOT_DASHBOARD_BACK_URL` | URL del enlace "← volver a la app" que la vista **standalone** pinta arriba. `null` = sin enlace. En modo `layout` se ignora (la navegación la da el chrome del host). |
| `extras_view` | `null` | `CHATBOT_DASHBOARD_EXTRAS_VIEW` | Nombre de una vista Blade del host (p. ej. `'admin._chatbot_widget'`) que `dashboard_layout.blade.php` `@include`a dentro de la sección, justo bajo el root del dashboard. La vista del host puede pintar markup directo o usar `@push('after_scripts')` (esta vez sí aterriza). Ver §5.2. |
| `asset_path` | `'vendor/chatbot/chatbot-dashboard.js'` | — | Ruta relativa al bundle JS del dashboard (publicado por `vendor:publish --tag=chatbot-assets`). |

---

## 4. Activar `pinnable()` en una tool

Por defecto **ninguna tool es pinnable**. El opt-in es explícito y se
acompaña de un enforcement estricto: `pinnable=true` se ignora si
`confirmation() !== Auto`. Tools que mutan datos (`create_*`, `update_*`,
`delete_*`) deben seguir devolviendo `false`.

### 4.1 Recipe básica

Añade el método al final de tu tool:

```php
/**
 * Permite que los blocks que esta tool produce aparezcan con el botón 📌
 * en el chat y puedan ser fijados al dashboard del usuario.
 *
 * Sólo válido si `confirmation() === Confirmation::Auto`. Para tools que
 * mutan datos o requieren confirmación explícita, deja el default (false).
 */
public function pinnable(): bool
{
    return true;
}
```

`BaseBackendTool::pinnable()` devuelve `false` por defecto — no necesitas
override en tools v1 existentes; el upgrade a v2.0 las deja intactas.

### 4.2 Ejemplo: tool listing-style (tabla pinnable)

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Tools;

use App\Models\Invoice;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class ListMyInvoicesTool extends BaseBackendTool
{
    public function name(): string { return 'list_my_invoices'; }
    public function description(): string { return 'Lista las facturas del usuario.'; }
    public function permissions(): array { return ['invoices.view']; }
    public function defaultScope(): AccessScope { return AccessScope::Self; }
    public function pinnable(): bool { return true; }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $rows = $this->accessibleQuery(Invoice::query(), $ctx)
            ->limit((int) ($args['limit'] ?? 20))
            ->get(['id', 'number', 'amount', 'status', 'issued_at']);

        return ToolResult::success(
            data: ['items' => $rows->toArray()],
            blocks: [[
                'type' => 'table',
                'data' => [
                    'rows' => $rows->toArray(),
                    'columns' => [
                        ['key' => 'number',   'label' => 'Nº'],
                        ['key' => 'amount',   'label' => 'Importe'],
                        ['key' => 'status',   'label' => 'Estado'],
                        ['key' => 'issued_at','label' => 'Fecha'],
                    ],
                ],
            ]],
        );
    }
}
```

Al ejecutarse, el orquestador estampa `id`, `source` y `pinnable=true` sobre
el block. El widget muestra el botón 📌 al hacer hover.

### 4.3 Ejemplo: tool stats-style (KPI pinnable)

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Tools;

use App\Models\Invoice;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class InvoiceStatsTool extends BaseBackendTool
{
    public function name(): string { return 'invoice_stats_this_month'; }
    public function description(): string { return 'Importe total facturado este mes.'; }
    public function permissions(): array { return ['invoices.view']; }
    public function defaultScope(): AccessScope { return AccessScope::Team; }
    public function pinnable(): bool { return true; }

    public function parametersSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $query = $this->accessibleQuery(Invoice::query(), $ctx)
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]);

        $current  = (float) $query->sum('amount');
        $previous = (float) Invoice::query()
            ->whereBetween('issued_at', [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ])
            ->sum('amount');

        $delta = $previous > 0 ? ($current - $previous) / $previous : 0.0;

        return ToolResult::success(
            data: ['current' => $current, 'previous' => $previous],
            blocks: [[
                'type' => 'kpi',
                'data' => [
                    'label'    => 'Facturación este mes',
                    'value'    => $current,
                    'format'   => 'currency',
                    'currency' => 'EUR',
                    'delta'    => $delta,
                    'caption'  => 'vs. mes anterior',
                ],
            ]],
        );
    }
}
```

### 4.4 Enforcement: confirmation === Auto

Si el método `confirmation()` de la tool devuelve `Confirmation::Confirm` o
`Confirmation::Manual`, el orquestador **ignora `pinnable=true`** y marca
el block con `pinnable=false`. La razón es seguridad: una tool que requiere
confirmación humana antes de ejecutarse en el chat no debe poder
re-ejecutarse silenciosamente desde el dashboard.

Para descubrir tools mal configuradas, `php artisan chatbot:tools:list`
emite un warning para cada tool donde `pinnable()=true && confirmation()!==Auto`:

```
WARN  invoice_dunning is pinnable() but confirmation() != Auto — pinnable will be ignored.
```

### 4.5 Page context al pin

Si la tool depende de `page_context` (porque está vinculada a una página
concreta — un detalle de cliente, una vista de mercado), ese contexto se
captura automáticamente al pinear. **No hay método para declarar claves**:
al pinear un block, el server toma un snapshot de **todas las claves string**
presentes en el `page_context` de la tool en ese momento y registra la lista
de claves capturadas en `source.page_context_keys` del widget.

El subset filtrado se guarda en `source.page_context_snapshot`; al replay,
solo ese subset capturado se aplica al `ToolContext` antes de ejecutar. Tras
el filter aplica un cap binario de `chatbot.limits.page_context_kb`
(default 16 KB) — si el JSON resultante lo excede, el snapshot completo se
descarta (`Log::info`). El handler debe por tanto apoyarse en el contexto que
la página expone al pinear; lo que no esté presente en `page_context` en ese
momento no se captura y no estará disponible al replay.

Detalle completo en [`page-context.es.md`](page-context.es.md).

---

## 5. Frontend: bundle, blade y data-attributes

### 5.1 El bundle `chatbot-dashboard.js`

El dashboard vive en un **bundle separado** del widget v1:

| Bundle | Cap CI gzip | Notas |
|---|---|---|
| `chatbot-widget.js` | 80 KB | Floating widget v1 + pin button + pin modal. |
| `chatbot-dashboard.js` | 150 KB | Grid + sidebar + WidgetCard + Chart.js default + KPI. |

Sólo se carga en `/chatbot/dashboard`. **No infla el widget**.

Pesos típicos en v2.0 (gzip):
- widget: ~28 KB / 80 cap (margen ~52 KB)
- dashboard: ~110 KB / 150 cap (margen ~40 KB)

El cap de CI se enforce en `scripts/build.mjs` — el build revienta con un
error claro si excedes alguno.

### 5.2 Ruta + blade

`DashboardController` (registrado bajo `chatbot.dashboard` route name) sirve
`GET /chatbot/dashboard`. La vista resuelve dinámicamente:

- **Extiende host layout** (`chatbot::dashboard_layout`): `@extends($layout)
  @section($section)`. Se usa cuando `chatbot.dashboard.layout` apunta a una
  vista que existe. **Es el modo recomendado en producción**: el dashboard
  hereda el header/sidebar/navegación del host. Mismo patrón que
  `chatbot::page_layout`.
- **Standalone** (`chatbot::dashboard`): HTML completo desde el paquete, **sin
  la navegación del host**. Se usa cuando `chatbot.dashboard.layout` es null o
  la vista no existe. Es un **fallback de último recurso** — el usuario que
  entra ahí queda sin acceso al resto de la app. Para no dejarlo en una isla
  sin salida, setea `chatbot.dashboard.back_url` y la vista pinta un enlace
  "← volver a la app" arriba.

> **v2.1.1 — el widget en modo `layout`.** En modo `layout`, si
> `chatbot.dashboard.mount_widget` está activo (default), la vista
> `dashboard_layout.blade.php` monta el `<chatbot-widget>` flotante ella misma
> vía `@push('after_scripts')` — sin eso, la página cuyo propósito es
> *coleccionar* bloques pineados sería la única donde no puedes generarlos. El
> stack `@stack('after_scripts')` lo exponen los layouts de Backpack (el
> destino documentado de `chatbot.dashboard.layout`); un host con un layout
> propio que no lo exponga, o que prefiera inyectar el widget por su cuenta,
> pone `mount_widget` a `false`. El modo **standalone** NO monta el widget en
> ningún caso — `mount_widget` sólo aplica en modo `layout`.
>
> **v2.1.3 — inyectar el widget del host en modo `layout`.** El widget
> que monta `mount_widget` es el bundle *pelado* del paquete: no carga el JS
> del host (renderers propios, frontend tools, page context
> `@chatbotBackpackContext`). Un host que quiera SU widget completo en el
> dashboard pone `mount_widget = false` y registra una vista propia en
> `chatbot.dashboard.extras_view` (p. ej. `'admin._chatbot_widget'`). La vista
> puede pintar el `<chatbot-widget>` + sus scripts directamente; o usar
> `@push('after_scripts')` para colocarlos al final del layout del host. El
> controller valida `View::exists()` y, si la vista no existe, degrada con
> log warning y sin extras (la página sigue renderizando). v2.1.3 también
> arregla el bug por el que el bundle del widget detecta el shim que el bundle del
> dashboard instala antes y se *upgrade*-a en sitio — ya no hay que cargar
> `chatbot-widget.js` desde el `<head>` para que `whenReady`/`registerTool`
> funcionen.
>
> _Nota — el stack `@stack('chatbot_dashboard_extras')` que v2.1.2
> intentó documentar **se ha retirado** en v2.1.3. Vivía dentro del
> `@section…@endsection` capturado, así que un `@push` desde el `$layout`
> view (la usage documentada) nunca llegaba a renderizarse. Si tu host hizo
> el push contra ese stack en v2.1.2, mueve ese mismo contenido a una vista
> Blade y registrala en `chatbot.dashboard.extras_view`._

Param opcional `?dashboard={slug}` deep-linkea un dashboard concreto. Si
el slug no existe o no pertenece al usuario, **NO devuelve 404** — la
página renderiza el empty state. Política consistente con `PageController`.

### 5.3 Atributos `data-*` del root

```html
<div
    id="chatbot-dashboard-root"
    data-dashboards-endpoint="{{ route('chatbot.dashboards.index') }}"
    data-theme="auto"
    data-chart-renderer="chartjs"
    data-use-bootstrap="0"
    data-debug="0"
    data-i18n="{...payload JSON-encoded...}"
    data-user-id="42"
    data-default-slug="mi-panel"
></div>
```

| Atributo | Inyectado por | Qué hace |
|---|---|---|
| `data-dashboards-endpoint` | Controller | URL base del CRUD JSON. El bundle deriva el resto desde aquí. |
| `data-theme` | Controller (config) | `light` / `dark` / `auto` (con `prefers-color-scheme`). |
| `data-chart-renderer` | Controller (config) | `chartjs` o `none`. Ver §8. |
| `data-use-bootstrap` | Controller (config) | `1` / `0`. Resuelto desde `chatbot.backpack.use_bootstrap`. Ver §5.6. |
| `data-debug` | Controller (`app.debug`) | `1` / `0`. El controller lo sigue emitiendo desde `config('app.debug')` para forward-compat, pero el bundle ya no lo lee — v2.1.3 retiró el botón 👁 "View source" que dependía de él (limpieza del header de la card). |
| `data-i18n` | Controller (lang) | JSON con `__('chatbot::chatbot')` — el bundle drena las claves UI. Ver §5.5. |
| `data-user-id` | Controller (auth) | Mirror del usuario activo para detección de logout cross-tab. |
| `data-default-slug` | Controller | Slug del dashboard a abrir por defecto. Prioridad: `?dashboard=` → `is_default=true` → null. |

### 5.4 Resolución del dashboard activo

```
prioridad: localStorage chatbot:active-dashboard:v1
            → data-default-slug
            → primer dashboard del usuario (cuando el sidebar carga la lista)
            → null (empty state)
```

`chatbot:active-dashboard:v1` es **per-origin localStorage** (mirror del
`chatbot:active-conversation:v1` del widget v1). Cambia cross-tab — si el
usuario navega entre dashboards en una pestaña, otra pestaña abierta en
`/chatbot/dashboard` lo refleja sin recargar.

### 5.5 Bridge i18n PHP → JS

Las claves UI del bundle viven en el archivo de lang publicado:

```php
// resources/lang/{en,es}/chatbot.php → 'dashboard' => [...]
'dashboard' => [
    'sidebar' => ['new_cta' => '+ New dashboard', /* ... */],
    'card'    => ['refresh' => 'Refresh', /* ... */],
    'header'  => ['refresh_all' => 'Refresh all', /* ... */],
    'pin'     => ['cta' => 'Pin to dashboard', /* ... */],
    'chart'   => ['invalid_data' => 'Chart data is invalid…', /* ... */],
    'kpi'     => ['no_value' => '—'],
],
```

El `DashboardController` emite todo el array como `data-i18n` JSON-encoded.
El bundle hace `JSON.parse` en boot y aplica cada subtree al mounter
correspondiente (`sidebar`, `widget-card`, `pin-modal`, `kpi.ts`).

**Si una clave falta o el atributo está ausente**, el bundle cae al
default inline en TS (inglés). Si el JSON no parsea, el bundle emite un
`console.warn` con preview truncado y sigue funcionando con defaults.

Para customizar:

```bash
php artisan vendor:publish --tag=chatbot-lang
# edita resources/lang/{locale}/vendor/chatbot/chatbot.php
```

Los hosts que embeben el widget v1 fuera de `/chatbot` también pueden
añadir el bridge manualmente:

```blade
<chatbot-widget
    data-endpoint="{{ route('chatbot.stream') }}"
    data-i18n="{{ json_encode(__('chatbot::chatbot'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
></chatbot-widget>
```

Si no añaden `data-i18n`, el widget sigue funcionando en inglés.

### 5.6 Theming: Bootstrap host-native vs CSS propio

El paquete reimplementa con CSS propio (`.cb-*`) primitivas visuales —
`card`, `table`, `list` — que un host Backpack ya carga vía Bootstrap 5.
v2.1 permite que esos block renderers usen las clases de Bootstrap del host
en lugar del CSS propio, para que el dashboard se vea como el resto del
panel admin en vez de "una isla" con su propia estética.

**Cómo funciona.** Los renderers (`table`/`card`/`list`) emiten *siempre*
ambos juegos de clases — `cb-table table table-sm table-striped …`. Lo que
cambia es qué CSS inyecta el bundle:

- **CSS propio** (default): el bundle inyecta `block-styles` + el polish de
  dashboard. Las clases `table`/`card`/`list-group` no matchean nada (no hay
  Bootstrap) y el CSS `.cb-*` del paquete pinta el bloque.
- **Bootstrap host-native**: el bundle **NO** inyecta su CSS de bloques. Las
  clases `.cb-*` no matchean nada (su CSS no está) y el Bootstrap del host
  pinta el bloque. Sin pelea de especificidad — sólo un juego de reglas
  activo a la vez.

**La matriz de superficies:**

| Superficie | ¿Ve el Bootstrap del host? | Estrategia |
|---|---|---|
| Dashboard en modo `layout` | ✅ Sí — hereda el `<head>` del host | Bootstrap host-native (si `use_bootstrap` lo activa). |
| Dashboard standalone | ❌ No — es una página HTML propia del paquete | CSS propio (`block-styles`). |
| Widget flotante `<chatbot-widget>` | ❌ No — vive en shadow DOM, aislado por diseño | CSS propio encapsulado, siempre. El Bootstrap del host no penetra el shadow DOM. |

**Config — `chatbot.backpack.use_bootstrap`:**

| Valor | Efecto |
|---|---|
| `'auto'` (default) | `true` sólo si el dashboard está en modo `layout` **y** el paquete Backpack está instalado. El modo standalone nunca tiene Bootstrap disponible, así que `auto` ahí siempre resuelve a `false`. |
| `true` | Fuerza el modo host-native. Útil para hosts con un layout Bootstrap-based no-Backpack. |
| `false` | Fuerza el CSS propio. Útil para hosts Backpack con un theme custom que prefieren el look del paquete. |

Env var: `CHATBOT_BACKPACK_USE_BOOTSTRAP`.

**Recomendación**: en un host Backpack, usa el modo `layout`
(`chatbot.dashboard.layout`) — es el camino "bonito": el dashboard hereda el
`<head>`, los bloques se ven host-native, y el shell del dashboard (sidebar,
header, grid de gridstack) mantiene su CSS propio porque Bootstrap no provee
un shell de dashboard. El widget flotante se queda siempre con su CSS propio
encapsulado: es shadow DOM por diseño y el Bootstrap del host no lo alcanza
— se tematiza vía las custom properties `--cb-*`.

> **Nota** — el dashboard no monta modals ni toasts propios (usa
> `window.confirm` para el borrado), así que no hay nada que delegar a
> `bootstrap.Modal`/`bootstrap.Toast`. El pin-modal del widget vive en el
> shadow root del widget y mantiene su implementación propia.

---

## 6. Refresh model

Cada widget tiene una `refresh_policy` independiente:

| Policy | Cuándo se replay | Default para |
|---|---|---|
| `on_open` | Al abrir `/chatbot/dashboard` (bulk SSE) + al click en ↻ | Default al pinear (`chatbot.dashboard.default_refresh_policy`). |
| `manual` | Sólo al click en ↻ por widget o "↻ todo" | Casos donde el replay es costoso o el usuario prefiere control. |
| `never` | Nunca; el snapshot persistido es la única vista | Datos históricos o snapshots de momento ("la facturación de septiembre"). |

### 6.1 Refresh bulk (SSE)

Al abrir el dashboard, el bundle dispara una sola request:

```
POST /chatbot/dashboards/{slug}/refresh
```

El servidor responde con un stream SSE, emitiendo un frame `widget_refreshed`
por cada widget con `refresh_policy='on_open'` (los `manual`/`never` se
ignoran):

```
event: widget_refreshed
data: {"widget_id":11,"status":"fresh","snapshot":{"data":{...},"captured_at":"..."},
       "error":null,"last_refreshed_at":"2026-05-13T10:01:00.000Z"}

event: widget_refreshed
data: {"widget_id":12,"status":"unauthorized","snapshot":null,"error":{...},
       "last_refreshed_at":null}

event: done
data: {"widget_count":2}
```

Paralelismo: el servidor usa `Concurrency::run()` con cap
`chatbot.dashboard.replay.concurrency` (default 8). Si un tool excede
`replay.timeout_seconds`, devuelve `status='error'` y el snapshot anterior
se conserva.

**Driver de concurrency**: el host debe publicar `config/concurrency.php` y
elegir un driver. `sync` (default seguro) ejecuta los replays
secuencialmente — funciona en cualquier entorno (Windows/WAMP, shared
hosting, contenedores sin `pcntl`). `process`/`fork` paralelizan de verdad
pero requieren subprocess/`pcntl` viables. Los tasks del paquete son
serializable-friendly (no capturan el grafo del `ReplayService`), así que
cualquier driver es seguro. Ver [`deployment.es.md`](deployment.es.md).

### 6.2 Refresh individual

Botón ↻ en el header de cada card:

```
POST /chatbot/dashboards/{slug}/widgets/{id}/refresh
```

Devuelve JSON sin stream — un único objeto bajo `data` con el **mismo shape
plano** que los frames del bulk SSE (`WidgetRefreshedFrame`):

```json
{ "data": { "widget_id": 11, "status": "fresh",
            "snapshot": { "data": {…}, "captured_at": "…" },
            "error": null, "last_refreshed_at": "2026-05-13T10:01:00.000Z" } }
```

Ambos endpoints de refresh (single JSON y bulk SSE) comparten un único
contrato. Cuenta como 1 hit en el rate limiter
(`replay.rate_limit_per_user_per_minute`); el bulk también cuenta como 1 hit.

---

## 7. Replay engine

`Rnkr69\LaraChatbot\Dashboard\ReplayService::replay()` es el corazón de v2.0.
Toma un widget persistido y devuelve un `RefreshResult` inmutable. Los
pasos:

```
1. Carga la tool → ToolRegistry::find($widget->source['tool'])
   → si null: status='source_missing', return.
2. Verifica pinnable() === true → si no: status='error', return.
3. Aplica cascada idéntica al chat:
   - Authorizer::can($user, $tool->permissions())
   - ScopeResolver::resolve($user, $tool->defaultScope())
   - TenantResolver::resolve($user, $tool, $pageContextSnapshot) (si bound)
   - Tool::handle() aplica ownership filter en query
4. Construye ToolContext:
   - actionId = nuevo UUID por replay
   - confirmation = 'auto' (estricto)
   - pageContext = page_context_snapshot guardado
5. Ejecuta → ToolResult
6. Mapeo ToolResult → WidgetRefreshStatus (selección por descriptor):
   - ok + existe el bloque {block_type, ordinal} → snapshot ← new data; status='fresh'
   - ok pero el tool ya no emite ese bloque → status='stale'; conserva snapshot anterior
   - error unauthorized → status='unauthorized'; conserva snapshot
   - error runtime/validation → status='error'; conserva snapshot
7. Persiste last_refreshed_at, last_refresh_status, last_refresh_error.
```

`replayBulk(Dashboard, User)` paraleliza con `Concurrency::run()`, chunkeado
al cap configurado.

> **v2.1.2 — selección de bloque en tools multi-bloque.** Un tool
> `pinnable()` puede emitir varios bloques en un mismo `ToolResult` — el caso
> canónico del dashboard es `fleet_kpis` devolviendo tres `kpi` + un `chart`.
> El widget no se fija a un UUID (el `id` del bloque se regenera en cada
> invocación del tool y nunca casaría en un replay posterior), sino a un
> **descriptor `{block_type, ordinal}`**: el orquestador estampa en cada frame
> `block` un `block_ordinal` 0-based — su posición *entre los bloques de su
> mismo tipo* en ese `ToolResult` — que viaja por el pin payload hasta
> `source.block_ordinal`. `ReplayService::mapResult()` re-selecciona el
> N-ésimo bloque de `widget.block_type` por ese descriptor. Si el tool cambió
> su salida y ya no existe ese bloque, el widget va a `stale` con un mensaje
> claro — **nunca** se persiste otro bloque como sustituto (eso era el bug:
> coger siempre `blocks[0]` corrompía datos en silencio o congelaba el
> `chart` en `stale` perpetuo). Los widgets pineados antes de 2.1.2 no tienen
> `block_ordinal` y caen a ordinal 0 (el primer bloque de su tipo) — sin
> migración de datos.

### 7.1 Tabla de `WidgetRefreshStatus`

| Status | Significado | Snapshot mostrado |
|---|---|---|
| `fresh` | Replay OK, datos actualizados. | Snapshot nuevo. |
| `stale` | Tool ejecutó pero devolvió un block diferente (cambió shape o tipo). | Snapshot anterior + badge ⚠ Stale. |
| `error` | Excepción runtime, timeout, validación. `last_refresh_error.message` tiene el detalle. | Snapshot anterior + badge Error. |
| `unauthorized` | El usuario perdió un permiso entre el pin y el refresh. | Snapshot anterior + badge Unauthorized. **Nunca datos frescos no autorizados.** |
| `source_missing` | El tool dejó de estar registrado (el host la eliminó o renombró). | Snapshot anterior + badge Source missing. |

### 7.2 Cascada de autorización

Idéntica al chat. La razón es estricta: si al pin el usuario podía ejecutar
`list_my_invoices` con `AccessScope::Team` pero al día siguiente perdió ese
permiso, el replay debe respetar la pérdida. Detalle completo de la cascada
en [`authorization.es.md`](authorization.es.md).

### 7.3 Failure modes que **conservan el snapshot anterior**

Toda categoría salvo `fresh` y `stale` (con block type igual) preserva el
snapshot persistido. La razón: prefieres ver datos antiguos correctamente
etiquetados como antiguos a perder el contenido del widget. El badge en la
card es la señal al usuario.

---

## 8. `chart_renderer` override

**Desde 0.4.4**, Chart.js (`chart.js/auto`) es el renderer **CORE** built-in
del bloque `chart`, embebido en **todas** las superficies — el widget
flotante, la página `/chatbot` y el dashboard — así que los charts se
renderizan idénticos en todas partes. No hay nada que registrar: un bloque
`chart` se dibuja out of the box.

> **`chart_renderer` ahora es informativo.** Antes gateaba si el bundle del
> dashboard registraba Chart.js (`'chartjs'`) o no (`'none'`). Con Chart.js en
> el core, `'none'` ya no lo elimina (el bundle del widget lo incluiría de
> todos modos, y la consistencia es justo el objetivo). La clave de config y el
> atributo `data-chart-renderer` se conservan por retrocompatibilidad pero ya
> **no cambian el rendering**. Para usar otra librería, registra un override (8.1).

Tipos soportados: `line`/`bar`/`pie`/`doughnut`/`polarArea`/`radar`/`bubble`/
`scatter`, con aliases LLM-friendly (`kind` → `type`; `series`/`points`/`values`
→ `datasets[0].data`; `categories` → `labels`). Detalle del shape en
[`block-renderers.es.md`](block-renderers.es.md).

### 8.1 Custom renderer (override del built-in)

Para usar otra librería (D3, ECharts…), regístrala en `window.Chatbot`. Un
renderer `chart` registrado por el host gana la cascada sobre el built-in:

```html
<script>
  window.Chatbot = window.Chatbot ?? {};
  window.Chatbot.registerBlockRenderer('chart', function(data, host) {
    // ... tu implementación
    return /* HTMLElement */;
  });
</script>
<script src="{{ asset('vendor/chatbot/chatbot-dashboard.js') }}" defer></script>
```

El bundle del dashboard detecta el registro existente y NO clobea el override.

---

## 9. Operativa

### 9.1 Statuses observables

Los `last_refresh_status` viajan tanto en el JSON detail como en cada frame
SSE bulk:

```json
{
  "id": 11,
  "block_type": "table",
  "last_refresh_status": "unauthorized",
  "last_refresh_error": {
    "category": "auth",
    "message": "Missing permission: invoices.view",
    "captured_at": "2026-05-13T10:00:00.000Z"
  }
}
```

El frontend mapea cada uno a un badge + tooltip. El backend nunca expone
mensajes server-internal al usuario; los detalles van a `last_refresh_error`
de la tabla `chatbot_dashboard_widgets` para auditoría.

### 9.2 Rate limiting

```php
'rate_limit_per_user_per_minute' => 60,
```

Token-bucket por usuario, aplicado **sólo** a:

- `POST /chatbot/dashboards/{slug}/widgets/{id}/refresh`
- `POST /chatbot/dashboards/{slug}/refresh` (1 hit, no n)

Cuando se agota, devuelve 429 con `Retry-After`. El bulk SSE protege
internamente con `replay.concurrency`, así que un widget mal configurado no
puede ahogar el servidor por descuido.

CRUD (lista/crear/pin/borrar/PATCH layout) NO está rate-limited — el coste
real está en re-ejecutar tools, no en escribir filas.

### 9.3 Comando de limpieza

`chatbot:dashboards:prune` hace housekeeping de filas inservibles
que se acumulan tras meses de uso. **Sin flags el comando sale con
error** — siempre se debe declarar explícitamente qué se prunea. Cuatro
modos opt-in (combinables en una sola invocación):

| Flag | Qué borra | Threshold | Override CLI |
|---|---|---|---|
| `--source-missing` | Widgets con `last_refresh_status='source_missing'` cuyo `last_refreshed_at < NOW() - N días` (tool desapareció del registry y sigue así). | `chatbot.dashboard.prune.source_missing_days` (default `30`) | `--source-missing-days=N` |
| `--stale` | Widgets cuyo `last_refreshed_at` es anterior a N días O `null`, excluyendo los que ya cuentan como `source_missing` (orphans de pin sin replay subsiguiente). | `chatbot.dashboard.prune.stale_days` (default `90`) | `--stale-days=N` |
| `--empty-dashboards` | Dashboards creados hace más de N días sin widgets activos (`whereDoesntHave('widgets')` sobre el scope SoftDelete normal). | `chatbot.dashboard.prune.empty_dashboard_days` (default `180`) | `--empty-dashboard-days=N` |
| `--purge-soft-deleted` | Hard-delete (`forceDelete()`) de widgets y dashboards cuyo `deleted_at < NOW() - N días`. Combinable con los anteriores; las filas recién soft-deleted por este mismo run NO se purgan (su `deleted_at` es de hace segundos). | `chatbot.dashboard.prune.purge_soft_deleted_days` (default `30`) | `--purge-older-than-days=N` |

**Dry-run por defecto.** Sin `--force` el comando lista los candidatos en
tablas pero no borra nada. Con `--force` ejecuta. Output siempre termina
con tres líneas resumen: `Mode: EXECUTED|DRY-RUN`, `Soft-deleted: N
(would: M)`, `Hard-deleted: N (would: M)`.

```bash
# Inspeccionar qué se borraría (no ejecuta):
php artisan chatbot:dashboards:prune --source-missing --stale

# Ejecutar el housekeeping completo:
php artisan chatbot:dashboards:prune \
    --source-missing --stale --empty-dashboards --force

# Hard-delete de filas soft-deleted hace más de 14 días (recovery agresivo):
php artisan chatbot:dashboards:prune \
    --purge-soft-deleted --purge-older-than-days=14 --force
```

**Soft-delete vs hard-delete**: los tres primeros modos llenan
`deleted_at` (paridad estricta con `DELETE /chatbot/dashboards/{slug}`
del endpoint API); `--purge-soft-deleted` es el único path al
`forceDelete` real. **No es paridad directa con `chatbot:cleanup-actions`**
— ese marca `status='expired'` sobre `chatbot_pending_actions` sin
tocar `deleted_at`; el prune borra filas inservibles del dashboard.

**Receta de scheduler** (`app/Console/Kernel.php` en el host):

```php
$schedule->command('chatbot:dashboards:prune', [
    '--source-missing', '--stale', '--empty-dashboards', '--force',
])->weekly();

// Opcionalmente, hard-delete mensual de lo ya soft-deleted:
$schedule->command('chatbot:dashboards:prune', [
    '--purge-soft-deleted', '--force',
])->monthly();
```

### 9.4 Bundle cap CI

`scripts/build.mjs` mide cada bundle post-build y revienta con
`process.exit(1)` si excede:

```
Bundle public-build/chatbot-widget.js   :  27.74 KB gzip /  80 KB cap ✔
Bundle public-build/chatbot-dashboard.js: 110.22 KB gzip / 150 KB cap ✔
```

Si un PR añade dependencias pesadas, el build falla **antes de mergear** —
no descubres la regresión en producción. El cap del widget protege el
TTFB de cualquier página del host (el bundle se sirve en todas las
páginas con `<chatbot-widget>`); el del dashboard protege `/chatbot/dashboard`
(carga sólo allí, donde el TTFB es más tolerable pero no infinito).
Ver [`distribution.es.md`](distribution.es.md) para el snippet YAML genérico del pipeline CI del host.

---

## 10. Migración desde v1.1.x

### 10.1 Pasos del host

```bash
composer update rnkr69/lara-chatbot:^0.4
php artisan migrate
php artisan vendor:publish --tag=chatbot-assets --force
# opcional: re-publicar config si quieres customizar dashboard.*
php artisan vendor:publish --tag=chatbot-config
```

### 10.2 Qué cambia y qué NO

| Aspecto | v1.1.x | v2.0 | Acción del host |
|---|---|---|---|
| `BlockPayload` shape | `{type, data}` | + `{id?, source?, pinnable?}` opcionales | Ninguna. Los renderers existentes ignoran los campos extra. |
| `BackendTool::pinnable()` | No existe | Default `false` en `BaseBackendTool` | Ninguna. Tools v1 siguen funcionando inertes. |
| Tablas | `chatbot_*` v1 | + `chatbot_dashboards`, `chatbot_dashboard_widgets` | `php artisan migrate`. |
| Rutas | `/chatbot/*` | + `/chatbot/dashboard*` (sólo si `chatbot.dashboard.enabled=true`) | Ninguna. |
| Eventos SSE | Frames base | + campos extra en `block` y `tool_result` | Ninguna; widgets v1 los ignoran. |
| Widget bundle | 18 KB gzip | ~28 KB gzip (con pin button + modal + setKpiLabels) | Re-publicar assets: `--tag=chatbot-assets --force`. |
| i18n | Defaults inline en TS | `data-i18n` opcional + defaults inline como fallback | Si quieres traducir el bundle, añade `data-i18n` a `<chatbot-widget>` (ver §5.5). |

### 10.3 Activar el dashboard en un host existente

1. Ejecuta los pasos de §10.1.
2. Decide si quieres exponer la ruta del dashboard: por defecto `enabled=true`,
   pero puedes ponerlo a `false` si tu host aún no usa el feature.
3. Para cada tool que el LLM emita resultados pinneables (tablas, KPIs,
   charts), añade `public function pinnable(): bool { return true; }`.
   Sólo en tools con `confirmation() === Auto`.
4. Inyecta el bundle del dashboard **sólo en `/chatbot/dashboard`**
   (no es obligatorio cargarlo en cada página del host). El widget v1
   funciona sin el bundle del dashboard.
5. Si añades un link a `/chatbot/dashboard` en el nav del host, usa la key
   `__('chatbot::chatbot.dashboard.menu_label')` ("My pinned dashboard" /
   "Mi panel fijado") como texto — es distinta del `dashboard_title` (el
   `<title>` HTML de la página) precisamente para que no colisione con un
   item "Dashboard" que el host ya tenga en su menú de admin.
6. Si el host quiere el bridge i18n, añade `data-i18n` a `<chatbot-widget>`
   en sus layouts (§5.5).

### 10.4 Hosts upgrading desde v1.1.x sin activar dashboard

Si el host quiere mantenerse en v1 features:

```env
CHATBOT_DASHBOARD_ENABLED=false
```

- Las rutas `/chatbot/dashboard*` no se registran.
- `pinnable()` se ignora silenciosamente.
- El bundle del dashboard no se sirve.
- El widget v1 sigue funcionando idéntico.

El bump a v2.0.0 sigue justificándose por los puntos del README
"Versioning policy" (`BlockPayload` cambia shape aditivamente, contrato
`Tool` gana método nuevo, migraciones nuevas) — son cambios formales que
requieren MAJOR aunque sean aditivos.

---

## 11. Security checklist

Checklist defensivo para hosts en producción. Cada punto es una propiedad
que el paquete garantiza; el host debe verificar en su integración que
nada de lo suyo la rompe. Cobertura de cada punto vive en
`tests/Feature/Http/DashboardSecurityTest.php` o en los tests de las secciones correspondientes.

| # | Propiedad | Garantía del paquete | Verificación recomendada del host |
|---|---|---|---|
| 1 | **CSRF en POST/PATCH/DELETE** | Las rutas `/chatbot/dashboards*` heredan el middleware `web` configurado en `chatbot.route.middleware` (default `['web', 'auth']`) — `VerifyCsrfToken` incluido por la cadena `web` de Laravel. | Si el host monta el paquete bajo otro grupo (e.g. `api`), asegúrese de inyectar CSRF (`Sanctum`/`web`) o use otra forma de validar el origen. |
| 2 | **XSS en `dashboard.name` / `widget.title`** | El paquete persiste y devuelve los strings **raw** (sin escape server-side). El cliente (`sidebar.ts:181`, `widget-card.ts:287`) usa `textContent`, no `innerHTML` — el escape es responsabilidad del DOM API. | Si el host reescribe el bundle del dashboard o registra renderers propios, NO usar `innerHTML`/`insertAdjacentHTML` con strings del usuario. Para text/card el paquete usa `renderMarkdown` que ya HTML-escapa input + valida hrefs (`markdown.ts`). |
| 3 | **XSS en snapshots persistidos** | Los renderers built-in del cascade (`renderTableBlock`, `renderCardBlock`, `renderListBlock`, `renderKpiBlock`, `renderChartBlockChartjs`) usan `textContent` para data del usuario; Chart.js dibuja en `<canvas>` (no HTML). El placeholder `text` y `card.description` van por `renderMarkdown` (HTML-escape + safeHref). | Si el host registra `window.Chatbot.registerBlockRenderer(...)`, verificar que ningún path inyecta `innerHTML` con data del block sin sanitizar primero. |
| 4 | **Authorization 404-no-403** | Todos los endpoints aplican `Dashboard::forUser($user)` antes de `findOrFail`; widgets ajenos devuelven 404 incluso si el ID es válido. | Confirme que `$user` no escapa al `Authorizer` del paquete (chat/dashboard comparten la cascada). |
| 5 | **`page_context_keys` filtering** | Al pinear, el server toma un snapshot de todas las claves string presentes en el `page_context` de la tool en ese momento y las registra en `source.page_context_keys`; el replay se restringe exactamente a ese subset capturado (`source.page_context_snapshot`). Tras el filter aplica cap binario `chatbot.limits.page_context_kb` (default 16 KB, descarte completo + `Log::info`). | El set capturado es lo que la página expone al pinear — no hay método de tool para declararlo. Las claves ausentes del `page_context` al pinear el block no se capturan y no estarán disponibles al replay. |
| 6 | **`source.args` re-validation al replay** | El replay re-ejecuta `$tool->execute($args, $ctx)` cada vez; el JSON Schema del tool valida args en cada invocación. Si el cliente pinea con args válidos pero el tool tira al refresh (schema cambió, runtime error, edge case), el endpoint devuelve 200 con `last_refresh_status='error'` + `last_refresh_error` y conserva el snapshot anterior — **nunca 500**. | Si una tool cambia su JSON Schema, los widgets pinneados antes del cambio degradan a `status=error` automáticamente — la UI ya sugiere "repinear desde el chat". |
| 7 | **Caps server-side (no opt-out)** | `max_dashboards_per_user` (default 20) y `max_widgets_per_dashboard` (default 50) se enforcen en los controllers — un cliente abusivo no puede crear infinitos rows. | Si su host atiende muchos usuarios, considere bajar los defaults vía config. |
| 8 | **Rate limit del replay** | `RateLimiter` por usuario sobre `refresh` + `refreshAll`; bulk SSE cuenta como 1 hit (no N por widget). **El CRUD NO entra al throttle** — limítelo desde su capa de proxy si lo necesita. | — |

Si encuentra una propiedad que el paquete debería garantizar y no está
en esta lista, ábralo como issue: la sección §11 vive aquí precisamente
para ser un contrato observable, no un disclaimer.

---

## 12. Conversational dashboard editing (v2.2)

v2.2 cierra el ciclo "crear + editar desde el chat" añadiendo cinco
backend tools que el LLM invoca directamente en lugar de pedirle al
usuario que use el modal manual o el menú de la card. Junto con un
auto-inject de page_context en `/chatbot/dashboard`, el LLM puede:

- "Añade mis KPIs al panel" → genera + pinea el widget en una sola acción.
- "Mueve el chart a la derecha y hazlo más grande" → mueve + redimensiona.
- "Renombra este dashboard a Operaciones Q1" → rename + slug regen.
- "Quita el widget de misiones" → soft-delete del widget.
- "Borra el panel viejo" → soft-delete del dashboard + auto-promote-next-default.

### 12.1 Tools nuevas

| Tool | `confirmation` | Caso |
|---|---|---|
| `add_to_dashboard` | `Auto` | Resuelve `source_tool`, lo ejecuta y pinea el block seleccionado al dashboard del user. |
| `edit_widget`      | `Auto` | Move/resize/rename + cambio de `refresh_policy`. Args opcionales combinables en una sola invocación. |
| `delete_widget`    | `Auto`* | Soft-delete del widget. |
| `edit_dashboard`   | `Auto` | Rename (regenera slug) + `is_default` (auto-demote vía model hook). |
| `delete_dashboard` | `Auto`* | Soft-delete + auto-promote-next-default. Refuses si es el único del user. |

\* **Nota sobre confirmación**: el plan original proponía
`confirmation = Confirm` para `delete_widget`/`delete_dashboard`, lo que
emitiría el banner del orquestador antes de aplicar. En v2.2.0 mantenemos
`confirmation = Auto` porque el flow de Confirm para backend tools (filtro
del catálogo en `ChatService` + banner SSE específico de BE + endpoint
`POST /actions/{id}/confirm` para BE) está pendiente del backlog v2.x.
El safety net en v2.2 es:

1. **Soft-delete recoverable** a nivel BD (la fila persiste, se purga
   cuando el cron `chatbot:dashboards:prune --purge-soft-deleted` corre
   en su ventana de gracia configurable, default 30 días).
2. **Lenguaje en la `description()`** de cada delete tool: instruye al
   LLM "Before invoking, CONFIRM verbally with the user". El system
   prompt v2.2 (§12.3) refuerza la regla.
3. **Guard `would_create_orphan_default`** en `delete_dashboard`: si es
   el único del user, devuelve error en vez de borrar.

Hosts conservadores que quieran prohibir delete desde chat ponen
`chatbot.tools.delete_widget.enabled = false` y/o
`chatbot.tools.delete_dashboard.enabled = false` — el system prompt deja
de mencionar la tool al LLM también.

### 12.2 Activación y opt-out

Las 5 tools se registran automáticamente al boot via
`chatbot.tools.backend_primitives` (paralelo del `frontend_primitives`
existente). Cada tool expone un flag individual:

```php
// config/chatbot.php
'tools' => [
    ...
    'add_to_dashboard' => ['enabled' => true],
    'edit_widget'      => ['enabled' => true],
    'delete_widget'    => ['enabled' => true],
    'edit_dashboard'   => ['enabled' => true],
    'delete_dashboard' => ['enabled' => true],
],
```

Quitar una línea de `backend_primitives` o setear `enabled => false`
omite la tool del `ToolRegistry` Y la quita de las hints del system
prompt (§12.3).

### 12.3 Hints del system prompt

Cuando `chatbot.system_prompt.decision_strategy = true` (default) el
`SystemPromptBuilder` concatena una sección **Personal Dashboard —
conversational tools (v2.2)** con bullets que mapean intent → tool:

```
### Personal Dashboard — conversational tools (v2.2)

When the user is on `/chatbot/dashboard` …, match these intents …:

- "add X to my dashboard" / "pin Y" → `add_to_dashboard`.
- "move / resize / rename a widget" → `edit_widget`.
- "remove / delete / unpin a widget" → `delete_widget`. **Confirm verbally**.
- "rename my dashboard" / "set as default" → `edit_dashboard`.
- "delete my dashboard" → `delete_dashboard`. **Confirm verbally**.

The `page_context.dashboard` (auto-injected on the dashboard page) carries the
current slug, widgets, titles and ids. …
```

Bullets de tools deshabilitadas (`enabled = false`) NO se emiten — el
LLM no las propone al user. Hosts con un `decision_strategy` view custom
(`decision_strategy: 'host::custom_strategy'`) NO reciben las hints
automáticamente; deben replicarlas en su vista si las quieren.

### 12.4 Auto-inject de `page_context.dashboard`

En `/chatbot/dashboard` (modo standalone y modo layout), el
`DashboardController` calcula el contexto del dashboard activo y lo
serializa en `data-dashboard-context` sobre `#chatbot-dashboard-root`:

```json
{
  "slug": "qa-210",
  "name": "QA 2.1.0",
  "is_default": true,
  "widgets": [
    {"id": 8, "title": "Average fare", "block_type": "kpi",
     "position": {"x": 0, "y": 0, "w": 5, "h": 4},
     "refresh_policy": "on_open", "last_refresh_status": "fresh"}
  ]
}
```

El bundle `chatbot-dashboard.js` lo lee al arrancar y llama
`window.Chatbot.setPageContext({dashboard: {...}})`. Como el bundle del
widget suele cargar DESPUÉS del bundle del dashboard (`@push('after_scripts')`
vs `@section('content')`), el dashboard espera al evento `chatbot:ready`
del widget bundle antes de emitir el page_context — el shim del
dashboard tiene `setPageContext` como noop por diseño.

**Cap binario**: si el JSON del dashboard context supera
`chatbot.limits.page_context_kb` (default 16 KB), `DashboardController`
trunca la lista de widgets a `{id, title}` y añade
`widgets_truncated: true` al payload — suficiente para que el LLM
matchee titles y emita widget_ids sin inflar el system prompt.

**Modo standalone sin widget**: si el host monta sólo el bundle del
dashboard (sin `<chatbot-widget>`), el evento `chatbot:ready` nunca se
dispara y el page_context queda sin emitir. Es comportamiento esperado:
no hay LLM al que enseñárselo.

**Cobertura del refresh del page_context** (refinado en v2.2.1):

| Trigger | ¿Se re-emite `page_context.dashboard`? | Mecanismo |
|---|---|---|
| Boot inicial (`/chatbot/dashboard` cargada) | Sí | `emitDashboardContext()` drena el atributo `data-dashboard-context` que el blade renderizó. |
| Switch entre dashboards desde la sidebar (mismo tab) | Sí (v2.2.1) | `loadDashboard()` llama `emitActivePageContext()` con el `DashboardDetail` recién fetcheado. |
| Mutación conversacional (chat invoca una de las 5 tools v2.2) | Sí (v2.2.1) | El bundle del widget despacha `chatbot:dashboard-mutation`; el listener del bundle del dashboard llama `loadDashboard()` / `emitActivePageContext()` según corresponda. Ver §12.6. |
| Mutación vía UI directa (gridstack drag/resize, rename inline en sidebar, click "Remove" del card) | **No** | Limitación pendiente. Backlog v2.3: subscribir a los eventos internos del bundle para re-emitir page_context sin recarga. |

Mientras la última fila no se cierre, el flujo "renombra el widget arrastrando
inline en la sidebar y luego pídele al LLM que lo mueva por su nuevo título" puede
fallar — el LLM ve el title viejo. El `widget_id` permanece estable, así que
"muévelo a la derecha" (resuelto por id) sigue funcionando incluso con el title
desincronizado.

### 12.5 Services internos (refactor)

PR-A y PR-B extraen tres services del dashboard, compartidos entre los
controllers HTTP existentes y las nuevas tools:

- **`Rnkr69\LaraChatbot\Dashboard\PinService`** — la lógica de pin (cap,
  pinnable enforcement, snapshot truncation, page_context filtering,
  source signature, persist + touch). Antes vivía inline en
  `ApiDashboardWidgetController::store`. Lanza `PinException` con
  categorías `cap_reached` / `not_pinnable`.
- **`Rnkr69\LaraChatbot\Dashboard\WidgetCrudService`** — `update()`
  selectivo (position/title/refresh_policy) y `delete()` soft. Antes
  inline en `ApiDashboardWidgetController::update/destroy`.
- **`Rnkr69\LaraChatbot\Dashboard\DashboardCrudService`** — `update()`
  (rename + slug regen + is_default), `delete()` (soft +
  auto-promote-next-default) y `deriveUniqueSlug()`. Antes inline en
  `ApiDashboardController`.

Plus el helper `WidgetPositionNormalizer::normalize($raw, $blockType)`
que sustituye al `preparePosition` que vivía duplicado entre `store` y
`update`.

**Sin cambio de contrato HTTP**: los responses de los controllers (incl.
shapes de error) son idénticos a v2.1.x; las suites
`ApiDashboardControllerTest` y `ApiDashboardWidgetControllerTest` pasan
sin modificación.

### 12.6 Cross-client signal `chatbot:dashboard-mutation` (v2.2.1)

Las 5 tools conversacionales mutan el servidor pero el dashboard ya montado
en la misma página no se entera por sí solo — necesita un puente
cliente↔cliente. v2.2.1 lo resuelve con una capa genérica de
`meta.side_effects` sobre los `block` frames del SSE:

1. **Backend (tool author)** — cada una de las 5 tools estampa un descriptor
   `meta.side_effects` sobre el `card` block que ya devolvía en éxito:

   ```php
   return ToolResult::success(
       data: [...],
       blocks: [[
           'type' => 'card',
           'data' => ['title' => '✅ Added', 'description' => '...'],
           'meta' => [
               'side_effects' => [
                   'type'           => 'widget_added',
                   'dashboard_slug' => $dashboard->slug,
                   'widget_id'      => (int) $widget->id,
               ],
           ],
       ]],
   );
   ```

2. **Orquestador (`ChatService`)** — propaga `meta` verbatim al frame `block`
   del stream SSE. El payload gana una clave `meta` cuando el tool la estampó
   (omitida cuando no, por back-compat con consumers v1/v2.0/v2.1).

3. **Widget bundle** — cuando el frame `block` llega con `meta.side_effects`
   que tenga `type` string, despacha un `CustomEvent` en `document`:

   ```js
   document.dispatchEvent(new CustomEvent('chatbot:dashboard-mutation', {
     detail: { type: 'widget_added', dashboard_slug: 'ops', widget_id: 42 },
   }));
   ```

4. **Dashboard bundle** — listener registrado en `startDashboardApp` que
   switch'ea sobre `detail.type`:

   | `detail.type`        | Acción |
   |---|---|
   | `widget_added` / `widget_deleted` | Reload del dashboard activo si `dashboard_slug` coincide + `sidebar.refresh()` (cambia widget count). |
   | `widget_updated`     | Reload del dashboard activo si coincide. |
   | `dashboard_updated`  | `sidebar.refresh()` + (si `new_slug`) `history.replaceState({dashboard: new_slug})` + actualizar `<h1>` con `new_name` + `emitActivePageContext()`. |
   | `dashboard_deleted`  | `sidebar.refresh()` + si era el activo, switch a `promoted_slug` o al primero disponible (o empty state). |

**Otros consumidores del rail**: el envelope `meta` es genérico — futuros
hooks cliente↔cliente del paquete (notificaciones cross-tab, eventos a
otros bundles del host) pueden estampar otras claves bajo `meta` sin tocar
el protocolo SSE. Hoy la única clave canónica es `meta.side_effects`.
Consumers que no conocen una clave la ignoran sin error.

---

## Referencias rápidas

- Replay engine código: `src/Dashboard/ReplayService.php`
- Modelos Eloquent: `src/Models/Dashboard.php`, `src/Models/DashboardWidget.php`
- Frontend bundle: `resources/js/dashboard/`
- Tests Pest backend: `tests/Feature/Dashboard/`, `tests/Unit/Dashboard/`
- Tests Vitest frontend: `tests/js/dashboard/`
- Tests Playwright E2E: `tests/e2e/dashboard.spec.ts`, `pin-from-chat.spec.ts`
- Config: `config/chatbot.php` → `'dashboard'`
- Lang: `resources/lang/{en,es}/chatbot.php` → `'dashboard'`
