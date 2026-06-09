# Frontend Tools — Guía de implementación

> Esta guía cubre el contrato `FrontendTool` (marker introducido en E08,
> ampliado en E11 con `BaseFrontendTool` y 9 primitivas core publicadas en
> `src/Tools/Frontend/`) y los flujos en los que el LLM "invoca" tools cuya
> ejecución real ocurre en el navegador del usuario, no en el backend.
>
> Lee primero `docs/backend-tools.md` — frontend tools heredan toda la
> mecánica de cascada de autorización + JSON Schema + `ToolResult` y sólo
> se diferencian en cómo el orquestador (`ChatService`, E08) emite el
> evento SSE.

---

## 1. ¿Qué es una frontend tool?

Una `FrontendTool` es una tool que el LLM razona como cualquier otra
(con `name`, `description`, `parameters`, `permissions`, ...) pero cuya
acción material la materializa el widget en el browser:

- `navigate` no abre el `Order::show` en el backend; le pide al widget
  que navegue a `/orders/123`.
- `show_toast` no toca BD; le pide al widget que muestre un toast en
  pantalla.
- `download_file` SÍ toca el backend (firma una URL), pero la descarga en
  sí la dispara el widget con `<a href download>`.

El orquestador detecta `instanceof FrontendTool` y, en lugar de emitir
`tool_call` + `tool_result`, emite `frontend_action` con todo lo que el
widget necesita.

---

## 2. Contrato

```php
namespace Rnkr69\LaraChatbot\Tools\Contracts;

interface FrontendTool extends BackendTool {}
```

`FrontendTool` es un **marker interface** sin métodos propios — extiende
`BackendTool` para que el host pueda registrarla en el mismo
`ToolRegistry` y para que la cascada de validación/autorización se
aplique de forma idéntica.

La forma natural de implementar una FE tool es extender
`Rnkr69\LaraChatbot\Tools\BaseFrontendTool`, que extiende a su vez
`BaseBackendTool` (DRY) y aporta un `handle()` por defecto que devuelve
`ToolResult::success([])`. Las primitivas del catálogo + DownloadFileTool
heredan de aquí.

```php
namespace App\Chatbot\Tools;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

class OpenInvoiceModalTool extends BaseFrontendTool
{
    public function name(): string { return 'open_invoice_modal'; }
    public function description(): string { return 'Open a modal showing the invoice details for an id.'; }
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ['type' => 'integer'],
            ],
            'required' => ['invoice_id'],
        ];
    }
}
```

Sin tocar nada más, el orquestador emitirá

```
event: frontend_action
data: {"tool":"open_invoice_modal","args":{"invoice_id":42},"action_id":"<uuid>","confirmation":"auto"}
```

cuando el LLM la invoque.

---

## 3. El "shim" de `BaseFrontendTool::handle()`

`handle()` por defecto devuelve `ToolResult::success([])`. El
`ChatService`:

1. Corre la cascada `BaseBackendTool::execute()` (validate args →
   permission → tenant → handle).
2. Si OK, genera un `action_id` UUID y emite `frontend_action` con
   `{tool, args + result.data, action_id, confirmation}`.
3. Mete `success(['status' => 'queued', 'action_id' => $uuid])` en el
   buffer que vuelve al LLM, para que el step se cierre coherentemente.

Si tu FE tool **no necesita lógica backend**, no override `handle()`. Si
sí (`DownloadFileTool` firma una URL, una tool propia podría resolver
slugs de un servicio externo), override y devuelve los campos a mergear
en `frontend_action.args`:

```php
public function handle(array $args, ToolContext $ctx): ToolResult
{
    $url = $this->signUrl($args['invoice_id']);

    return ToolResult::success([
        'pdf_url' => $url,
    ]);
}
```

El widget recibirá `frontend_action.args.pdf_url` además de los args
originales del LLM.

---

## 4. Niveles de confirmación

El enum `ConfirmationLevel` (`Auto|Confirm|Manual`) lo define `BackendTool`
y se hereda. La diferencia con backend tools v1:

- **Backend tools v1** sólo soportan `Auto` end-to-end (D9 §1 PROGRESS).
  El orquestador filtra y avisa por log.
- **Frontend tools** sí soportan los tres niveles desde E11 (estructura) y
  E16 (storage de pending actions) — el `frontend_action` lleva el flag
  para que el widget decida si auto-ejecuta, pide confirmación al usuario
  o marca como manual.

Cómo aplicarlo: override `confirmation()` y devuelve el nivel deseado.
El widget (E12+) interpreta:

| Nivel    | Comportamiento del widget |
|----------|--------------------------|
| `auto`   | Ejecuta inmediatamente al recibir el evento. |
| `confirm`| Muestra UI "¿Confirmas X?" antes de ejecutar. E16 persiste la acción pendiente. |
| `manual` | El usuario debe disparar la acción explícitamente desde un botón en el chat. |

---

## 5. Catálogo de primitivas core

Las 8 primitivas viven en `src/Tools/Frontend/` y se registran
automáticamente vía `chatbot.tools.frontend_primitives`. Cada una expone
una `description()` cuidada — el LLM elige tool basándose exclusivamente
en ese texto, así que respétalo o extiende la primitiva con un wording
mejor adaptado al dominio del host.

> **v1.1.2** — la primitiva `highlight` fue retirada del catálogo
> (finding #15). El outline temporal de 2 s sobre un selector
> LLM-construido entregaba poco valor y enmascaraba fallos silenciosos:
> cuando el selector no matcheaba, el primitive devolvía sin avisar y el
> LLM declaraba éxito. Para los casos de uso reales usa `navigate`,
> `render_block` o `fill_form` + `invoke_host_action('refreshGrid')`.

| Tool                     | `name()`             | Confirmation | Args principales                                        |
|--------------------------|----------------------|--------------|---------------------------------------------------------|
| `NavigateTool`           | `navigate`           | auto         | `url` o `route` + `params`                              |
| `ToggleVisibilityTool`   | `toggle_visibility`  | auto         | `selector`, `action` (`show\|hide\|toggle`)             |
| `FillFormTool`           | `fill_form`          | confirm      | `fields[]` (required), `selector?`, `form_id?`, `submit?` |
| `ShowToastTool`          | `show_toast`         | auto         | `message`, `level?` (`info\|success\|warning\|error`)   |
| `OpenModalTool`          | `open_modal`         | auto         | `title`, `block` (typed block), `actions?[]`            |
| `RenderBlockTool`        | `render_block`       | auto         | `type`, `data`                                          |
| `InvokeHostActionTool`   | `invoke_host_action` | manual       | `action_name`, `args?`                                  |
| `DownloadFileTool`       | `download_file`      | auto         | `url_or_disk_path`, `filename?`, `mime?`, `expires_in?` |

### 5.1 `NavigateTool`

Lleva al usuario a otra pantalla. SPA usa el adaptador registrado vía
`window.Chatbot.registerNavigator(...)` (E13); MPA cae a
`window.location.assign`.

```
Usuario: "abre la lista de pedidos"
LLM    : navigate({route: 'orders.index'})
Widget : Inertia.visit('/orders')   // o location.assign
```

### 5.2 `ToggleVisibilityTool`

`show|hide|toggle` sobre uno o varios elementos. Útil para flujos de
disclosure progresivo ("muéstrame los filtros avanzados").

### 5.3 `FillFormTool`

Rellena un formulario y opcionalmente lo envía. **Default `confirm`**
porque el caso típico (`submit=true`) dispara una acción de backend; el
host puede subclase para devolver `auto` si su uso real es siempre
"sólo precargar borradores".

Targeting (v1.1.2, finding #9.f):

1. **`selector`** (preferido) — CSS selector que resuelve al `<form>`
   directamente o a un wrapper cuyo primer `<form>` descendiente se usa.
   Lo emite el page context provider de Backpack como
   `crud.form.selector = '[bp-section="crud-operation-create"] form'`,
   apoyándose en el contrato `bp-section` estable de Backpack 5/6/7.
2. **`form_id`** — alternativa: id de un `<form>` o de un wrapper con
   `data-chatbot-form`. Útil cuando el host etiqueta los forms.
3. **Auto-discovery** — si no se pasa nada, busca el primer `<form>`
   plausible (`main form`, `form#crudTable`, `form.form`, then any
   `form`). Drop un `console.warn` para diagnóstico.

Los `fields[].name` matchean tanto el atributo HTML `name` como el alias
amigable `data-chatbot-field` (el alias gana cuando ambos existen). Si el
LLM llama con un name inexistente, el warn de consola lista los dos
conjuntos para diagnóstico.

```
Usuario: "rellena el formulario con priority=express, risk=high"
LLM    : fill_form({
           selector: '[bp-section="crud-operation-create"] form',
           fields: [
             {name: 'priority', value: 'express'},
             {name: 'risk', value: 'high'},
           ],
         })
Widget : muestra "¿Confirmas la modificación del formulario?"
```

Para hosts Backpack la sincronización con `crud.form.{selector, fields[]}`
del page context permite que el LLM resuelva FK selects (`Mars → 2`)
sin guessing — ver [`integrations/backpack.md §5`](integrations/backpack.md).
Para forms custom (no-Backpack), publica el schema con la directiva
`@chatbotForm` — ver [`integrations/custom-forms.md`](integrations/custom-forms.md).

### 5.4 `ShowToastTool`

Notificación efímera. NO la uses para preguntas — los toasts auto-cierran;
para preguntar usa el chat o un modal.

### 5.5 `OpenModalTool`

Modal overlay con un bloque tipado dentro y botones de acción opcionales.
Si las `actions[]` incluyen tools destructivas, considera subclase para
devolver `confirm`.

```
LLM    : open_modal({
           title: 'Confirmar borrado',
           block: {type: 'card', data: {summary: '3 pedidos archivados'}},
           actions: [
             {label: 'Borrar', tool: 'archive_orders', args: {ids: [1,2,3]}},
             {label: 'Cancelar'},
           ],
         })
```

### 5.6 `RenderBlockTool`

Inserta un bloque en el hilo del chat (no overlay). Para respuestas ricas
inline. Renderers concretos en E15.

### 5.7 `InvokeHostActionTool`

Escape hatch. El host registra acciones JS con
`window.Chatbot.registerAction('refreshGrid', () => {...})`. Default
`manual` por contrato conservador.

### 5.8 `DownloadFileTool`

Genera una URL firmada con expiración para descargar archivos del host.
**Excepción al patrón shim**: su `handle()` ejecuta lógica backend.

#### Configuración

```php
// config/chatbot.php
'tools' => [
    'download_file' => [
        'allowed_disks'  => ['s3-invoices', 'r2-attachments'],
        'max_expires_in' => 3600,
    ],
],
```

`allowed_disks` vacío = ningún disk permitido (fail-secure default).

#### Ownership

Subclase y override `assertCanDownload()` para reglas de dominio:

```php
namespace App\Chatbot\Tools;

use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

class HostDownloadFileTool extends DownloadFileTool
{
    protected function assertCanDownload(string $disk, string $path, ToolContext $ctx): ?ToolResult
    {
        // Sólo PDFs de OPAs cuyo owner sea el usuario actual
        if (preg_match('#invoices/(\d+)\.pdf$#', $path, $m)) {
            $invoice = \App\Models\Invoice::find((int) $m[1]);
            if ($invoice === null || $invoice->user_id !== $ctx->user->getAuthIdentifier()) {
                return ToolResult::error('not_owner', 'No puedes descargar esta factura.');
            }
        }
        return null;
    }
}
```

Y reemplaza la primitiva default en config:

```php
'frontend_primitives' => [
    // ...
    \App\Chatbot\Tools\HostDownloadFileTool::class, // en lugar de la default
],
```

#### Flujo en el widget

```
LLM   : download_file({url_or_disk_path: 's3-invoices::2026/123.pdf', filename: 'factura.pdf'})
Tool  : Storage::disk('s3-invoices')->temporaryUrl(...)
SSE   : event: frontend_action
        data: {tool, args: {url_or_disk_path, filename, download_url, expires_at}, action_id, confirmation: 'auto'}
Widget: <a href="<download_url>" download="factura.pdf">  → click programático
```

---

## 6. Eventos y persistencia

El evento `Rnkr69\LaraChatbot\Events\ToolInvoked` (gap E08) se dispara también
para frontend tools — incluyendo cuando la cascada las rechaza. Listeners
de audit/PII reciben la invocación igual que para backend tools.

`tool_call` y `tool_result` SSE se omiten para frontend tools (su shape
informativo es `frontend_action`). Si la cascada las rechaza, `ChatService`
emite `tool_result` con `ok=false` (informativo) y devuelve el error al
LLM.

---

## 7. Recetas de override

| Caso | Receta |
|---|---|
| Cambiar `confirmation` de una primitiva | Subclase + override `confirmation()`. Reemplazar la entrada en `chatbot.tools.frontend_primitives`. |
| Inyectar permisos a una primitiva | Subclase + override `permissions()`. Las primitivas core devuelven `[]` (público). |
| Adaptar el wording al dominio del host | Subclase + override `description()`. El LLM lee este texto, no el del paquete. |
| Aplicar tenant scope (E04) | Subclase + override `tenantScope(): bool` → true + bind un `TenantResolver`. Ojo: la cascada lo aplica antes del shim. |
| FE tool con backend logic | Override `handle()` y devuelve `ToolResult::success([campos a mergear])`. ChatService los inyecta en `frontend_action.args`. |
