# Block renderers

*[English](block-renderers.md) · Español*

Los bloques tipados permiten al LLM emitir contenido estructurado — tablas, tarjetas, botones de acción, widgets arbitrarios definidos por el host — en lugar de markdown plano. El widget renderiza cada bloque mediante una cascada de tres pasos para que los hosts puedan personalizar tan poco o tanto como necesiten.

---

## Cómo llega un bloque a pantalla

El backend tiene dos caminos para mostrar un bloque al usuario. Ambos terminan en la misma cascada de renderizado en el navegador, así que la elección es una cuestión de ergonomía, no de capacidad.

1. **`RenderBlockTool` (recomendado).** El LLM invoca la herramienta frontend integrada `render_block` con `{type, data}`. El orquestador (`ChatService`) emite un frame SSE `frontend_action` con `tool=render_block`. El widget intercepta este frame específico y empuja el bloque al mensaje del asistente actual — mismo camino que si hubiera llegado como `event: block`.
2. **Frame SSE `event: block` (backends personalizados).** Un servicio personalizado puede emitir `SseEvent::block($type, $data)` directamente. El widget maneja los frames `block` de forma idéntica a la acción `render_block` interceptada.

En cualquier caso, la cascada de renderizado es la que produce el DOM:

```
window.Chatbot.registerBlockRenderer(type, fn)   ← el renderer JS gana directamente
        ↓ (si no está registrado)
<template data-chatbot-block-template="<type>">  ← clon HTML declarativo
        ↓ (si no hay template)
renderer integrado para el tipo                  ← incluido en este paquete
        ↓ (si ninguno coincide)
[unsupported block: <type>]                      ← placeholder silencioso
```

Un renderer que lanza una excepción **no** envenena el hilo — la cascada continúa con el siguiente paso y el error se registra en `console.error`.

---

## Tipos de bloque integrados

| Tipo      | Propósito                                              | Claves obligatorias | Claves opcionales |
|-----------|--------------------------------------------------------|---------------------|-------------------|
| `text`    | Cuerpo Markdown (subconjunto negrita/cursiva/código/enlaces). | `content`    | —                 |
| `card`    | Resumen con título, campos clave/valor y acciones.     | `title`             | `subtitle`, `description`, `fields[]`, `actions[]` |
| `table`   | Datos tabulares, ordenables visualmente por CSS del host. | `rows[]`         | `columns[]`, `caption`, `empty_text` |
| `list`    | Ítems ordenados o no, opcionalmente clicables.         | `items[]`           | `title`, `ordered` |
| `actions` | Fila de botones en línea que disparan prompts/herramientas. | `actions[]`   | —                 |
| `chart`   | Placeholder. Los hosts registran su propio renderer.   | (cualquiera)        | `title`, `series`/`points`/`values` |

### `text`

```json
{ "type": "text", "data": { "content": "**Listo** — el pedido se actualizó." } }
```

### `card`

```json
{
  "type": "card",
  "data": {
    "title": "Order #142",
    "subtitle": "Pending shipment",
    "description": "Estimated delivery **next week**.",
    "fields": [
      { "label": "Customer", "value": "Acme Inc." },
      { "label": "Total",    "value": 1234.5 }
    ],
    "actions": [
      { "label": "Open", "prompt": "open order 142" }
    ]
  }
}
```

### `table`

`columns` es opcional. Cuando se omite, el widget infiere las cabeceras a partir de las claves de la primera fila — muy útil para respuestas ad-hoc del LLM.

```json
{
  "type": "table",
  "data": {
    "caption": "Recent orders",
    "columns": [
      { "key": "id",       "label": "ID" },
      { "key": "customer", "label": "Cliente" },
      { "key": "total",    "label": "Total" }
    ],
    "rows": [
      { "id": 1, "customer": "Acme",    "total": 99 },
      { "id": 2, "customer": "Globex",  "total": 250 }
    ]
  }
}
```

`rows` vacío renderiza el valor de `empty_text` (por defecto `No rows.`) encima de las cabeceras de la tabla.

### `list`

```json
{
  "type": "list",
  "data": {
    "title": "Next steps",
    "ordered": true,
    "items": [
      "Review the draft",
      { "text": "Open the dashboard", "prompt": "open dashboard" },
      { "text": "Run audit", "tool": "run_audit", "args": { "scope": "tenant" } }
    ]
  }
}
```

Los ítems con `prompt` o `tool` se renderizan como botones; las cadenas simples se renderizan como texto inerte.

### `actions`

```json
{
  "type": "actions",
  "data": {
    "actions": [
      { "label": "Yes", "prompt": "confirm" },
      { "label": "No",  "prompt": "cancel" }
    ]
  }
}
```

Misma forma de ítem que dentro de `card.actions` y `list.items` — elige el contenedor que mejor se lea en la conversación.

### `chart`

El **bundle del widget** incluye un placeholder para `chart`. El widget se mantiene pequeño y el host elige la librería de gráficos que se adapta a su sistema de diseño. El placeholder muestra el título (si lo hay), una pista apuntando a `registerBlockRenderer`, y un `<details>` colapsable con el payload crudo para que los datos no se pierdan mientras el host conecta un renderer real.

El **bundle del dashboard** (v2.0) incluye Chart.js como renderer por defecto para `chart` — consulta [`dashboard.es.md`](dashboard.es.md) para la configuración `chart_renderer` (`chartjs` | `none`) y cómo registrar tu propio renderer que lo sobreescriba. En páginas donde solo se carga el widget, sigue aplicando el placeholder; el bundle del dashboard es el que viene con las baterías incluidas.

Para registrar un renderer que gane en ambos bundles (debe ejecutarse **antes** de que el bundle del dashboard se inicialice):

```js
window.Chatbot = window.Chatbot ?? {};
window.Chatbot.registerBlockRenderer('chart', (data, host) => {
  const canvas = document.createElement('canvas');
  // …dibujar con Chart.js / ApexCharts / tu propio SVG…
  return canvas;
});
```

El bundle del dashboard detecta un registro existente y no lo sobreescribe.

### `kpi`

Introducido en v2.0. Renderiza una única cifra cuantitativa con contexto opcional (delta respecto al período anterior, flecha de tendencia, caption). El renderer integrado vive en `resources/js/kpi.ts` y se registra en `BUILTIN_BLOCK_RENDERERS` — **tanto** el widget como el bundle del dashboard lo usan a través de la misma cascada. Sin registro adicional.

```jsonc
{
  "type": "kpi",
  "data": {
    "label":    "Revenue this month",   // opcional (alias: title|name)
    "value":    1234567,                 // número | cadena pre-formateada
    "unit":     "USD",                   // opcional — detección automática de moneda ISO
    "delta":    0.12,                    // opcional — deriva la tendencia automáticamente
    "trend":    "up",                    // override opcional: 'up'|'down'|'flat'
    "format":   "currency",              // 'number'|'currency'|'percent'
    "caption":  "vs. last month",        // texto pequeño opcional
    "locale":   "en-US",                 // opcional — por defecto html[lang] o 'en-US'
    "currency": "EUR"                    // opcional — sobreescribe unit cuando ambos están presentes
  }
}
```

**Reglas de renderizado:**

- `value` numérico sin `format` → agrupación según locale; notación compacta cuando `abs(value) >= 100_000` (ej. `1.23M`).
- `value` cadena — escape hatch para LLMs que pre-formatean (`"$1.2B"`). Solo se coerciona a número si `format` está definido.
- `format: 'percent'` espera una fracción (`0.42 → "42%"`). Para renderizar 42 como número con unidad `%`, usa `format: 'number'` + `unit: '%'`.
- `delta` numérico → formateado con `signDisplay: 'exceptZero'` para que los positivos auto-prefijen `+`. Las cadenas se muestran tal cual.
- `trend` explícito tiene prioridad sobre `trend` derivado del signo de `delta`.
- Sin `value` válido y sin `label` → renderiza el placeholder mínimo `"—"`.

**Ejemplo en PHP** (una herramienta de estadísticas que emite un bloque KPI — consulta [`backend-tools.es.md`](backend-tools.es.md) para la receta `pinnable()` de contexto):

```php
return ToolResult::success(blocks: [[
    'type' => 'kpi',
    'data' => [
        'label'    => 'Active users',
        'value'    => 42_350,
        'delta'    => 1_200,
        'format'   => 'number',
        'caption'  => 'last 24h',
    ],
]]);
```

**i18n:** la cadena placeholder para "sin valor resuelto" (por defecto `'—'`) se puentea desde PHP mediante `chatbot::chatbot.dashboard.kpi.no_value` cuando el host emite `data-i18n` en `<chatbot-widget>` o `#chatbot-dashboard-root` — consulta [`dashboard.es.md`](dashboard.es.md).

---

## Personalizar bloques

### 1. Registrar un renderer JS (control total)

`registerBlockRenderer` tiene prioridad sobre el template del host y sobre el integrado. Úsalo cuando necesites escuchadores de eventos, una librería de gráficos, o cualquier lógica que el template declarativo no puede expresar.

```html
<script>
  window.Chatbot.registerBlockRenderer('order_card', (data, host) => {
    const root = document.createElement('article');
    root.className = 'order-card';
    root.innerHTML = `
      <h3>Order #${data.id}</h3>
      <p>${data.summary}</p>
    `;
    root.querySelector('h3').addEventListener('click', () => host.send(`open order ${data.id}`));
    return root;
  });
</script>
```

Los renderers reciben `(data, host, meta?)`:

- `data` — el payload del bloque del frame SSE.
- `host` — `{ send(prompt: string): void }`. **No** es el contenedor DOM — tu renderer debe **retornar** el `HTMLElement` y el widget lo anexa. `host.send(prompt)` encola un mensaje de usuario de seguimiento exactamente como si el usuario lo hubiera escrito; deja claro en tu UI cuando un clic dispara un prompt — los usuarios encuentran desconcertante el envío silencioso.
- `meta` (opcional, desde v1.1) — metadatos en tiempo de ejecución. Hoy el único campo es `meta.customError`, que se activa cuando un renderer del host previamente registrado para el mismo `type` lanzó una excepción y la cascada cayó al integrado. Úsalo para mostrar un diagnóstico útil en lugar del error predeterminado engañoso ("renderer not registered") — eso es exactamente lo que hace el fallback integrado de `chart` en v1.1.

> **Error común:** asumir que `host` es el nodo DOM y llamar `host.appendChild(...)`. No lo es. Retorna el elemento que construiste; el widget lo envuelve por ti.

### 2. Declarar un template (sin JS)

Una alternativa declarativa para el caso común "quiero mi propio markup pero sin comportamiento". Añade un `<template data-chatbot-block-template="<type>">` en cualquier parte de la página; el widget lo clona para cada bloque coincidente y recorre cada descendiente `[data-bind="path"]`, poblando `textContent` desde `data` mediante una búsqueda de ruta de puntos (`user.email`, `tags.0`, …).

```html
<template data-chatbot-block-template="order_card">
  <article class="my-order-card">
    <h3 data-bind="title"></h3>
    <p data-bind="description"></p>
    <dl>
      <dt>Customer</dt><dd data-bind="customer"></dd>
      <dt>Total</dt><dd data-bind="total"></dd>
    </dl>
  </article>
</template>
```

El widget añade las clases `block` y `block-<type>` a la raíz clonada si el template no las incluía ya, para que el CSS global del widget siga aplicando.

Los templates viven en el DOM ligero del host (no en el shadow root del widget). El widget re-consulta el documento en cada render, por lo que puedes añadir o reemplazar templates dinámicamente (las navegaciones SPA de Inertia / Livewire funcionan bien).

### 3. Sobreescribir un integrado

No existe un hook "extender el integrado" en v1; llamar `registerBlockRenderer('table', fn)` reemplaza por completo el renderer `table` integrado. Si quieres el estilo por defecto pero comportamiento personalizado en algunas filas, copia el código fuente del integrado (`resources/js/blocks.ts → renderTableBlock`) en tu renderer y parchea desde ahí.

---

## Ejemplo de extremo a extremo: backend → widget

```php
// app/Chatbot/Tools/ListMyOrdersTool.php
class ListMyOrdersTool extends BaseBackendTool
{
    public function name(): string { return 'list_my_orders'; }

    protected function handle(array $args, ToolContext $ctx): ToolResult
    {
        $rows = Order::forUser($ctx->user)->latest()->limit(10)->get(['id', 'customer', 'total'])->toArray();

        // Retornar un bloque `table` en `blocks` permite al orquestador emitirlo
        // junto al texto del asistente. Alternativamente, el LLM puede llamar
        // `render_block` él mismo con la misma forma.
        return ToolResult::success(
            data: ['count' => count($rows)],
            blocks: [[
                'type' => 'table',
                'data' => [
                    'caption' => 'Tus últimos pedidos',
                    'columns' => [
                        ['key' => 'id', 'label' => 'ID'],
                        ['key' => 'customer', 'label' => 'Cliente'],
                        ['key' => 'total', 'label' => 'Total'],
                    ],
                    'rows' => $rows,
                ],
            ]],
        );
    }
}
```

El widget renderiza la tabla sin ningún código del host — el renderer `table` integrado gestiona columnas inferidas, estados vacíos y filas array-vs-object.

---

## Referencia de la cascada

| Paso | Qué se ejecuta                                                   | Cuándo                                                          |
|------|------------------------------------------------------------------|-----------------------------------------------------------------|
| 1    | `window.Chatbot.registerBlockRenderer(type, fn)`                 | El host llamó `registerBlockRenderer` para este `type`.         |
| 2    | `<template data-chatbot-block-template="<type>">` clonado y enlazado | Existe un elemento template coincidente en el documento.   |
| 3    | Renderer integrado (`text` / `card` / `table` / `list` / `actions` / `chart`) | El tipo coincide con uno de los integrados.          |
| 4    | Placeholder `[unsupported block: <type>]`                        | Ninguno de los anteriores coincidió.                            |

Cada paso es best-effort: si el paso 1 lanza, se intenta el paso 2; si el paso 2 lanza, se ejecuta el paso 3.
