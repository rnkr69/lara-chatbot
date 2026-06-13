# Decision strategies — Page-aware prompting

*[English](decision-strategies.md) · Español*

> Cómo enseña el package al LLM a aprovechar la página actual del usuario
> en vez de duplicar contenido en el chat. Introducido en v1.1.1.

Pre-lectura: [`page-context.es.md`](page-context.es.md) (cómo se sanitiza el page
context) + [`FRONTEND_TOOLS.es.md`](FRONTEND_TOOLS.es.md) (catálogo de primitivas
que el LLM puede usar para actuar sobre la página).

---

## 1. ¿Por qué este doc?

Una conversación natural en un admin tiene dos rutas para entregar un
resultado:

- **Chat-bound**: el LLM llama una backend tool, recibe los datos y los
  pinta como un bloque (tabla, KPI, chart) DENTRO del chat.
- **Page-bound**: el LLM llama una frontend tool que modifica la UI que
  el usuario ya tiene en pantalla (filtrar el grid, abrir un drawer,
  rellenar un form).

Para una tarea como *"enséñame las últimas 50 misiones a Marte"* desde
`/admin/mission` (list view de Backpack), las dos rutas funcionan
"técnicamente", pero la experiencia es muy distinta:

| Ruta | UX | Tokens | Persistencia |
|---|---|---|---|
| Chat-bound (`list_my_missions` → `render_block` table) | Tabla duplicada bajo la tabla nativa que el usuario ya ve. Saturación visual. | ~3000 tokens out + se guardan en `chatbot_messages` y vuelven al contexto siguiente turn. | El bloque se persiste, se re-cargará en futuras visitas a la conversación. |
| Page-bound (`navigate({url: '/admin/mission?...'})`) | Filtra la DataTable existente vía querystring. UX nativa. | ~50 tokens out. La data nunca entra al contexto del LLM. | Reversible con un click del usuario. |

Sin guidance, el LLM elige la primera porque es la más obvia desde la
perspectiva de "qué tool usar para responder esto". El package añade una
sección al system prompt que le enseña a preferir la segunda cuando la
página lo permite.

---

## 2. Las reglas (qué ve el LLM)

La sección "Page context — decision strategy" se concatena al system
prompt tras `## Current page`. Su contenido canónico:

```
### Listings (`crud.action = list`)
- User asks to filter / search the SAME entity → prefer modifying the
  current grid. Backpack default list views do NOT expose a `<form>` for
  filters (they render popover dropdowns wired by Ajax), so
  `fill_form({form_id: 'filtersForm'})` silently fails. Instead build the
  URL with the querystring and call
  `navigate({url: '/admin/{entity}?{filter}={value}'})`. Only custom hosts
  that DO expose a `<form>` can use `fill_form` + `invoke_host_action('refreshGrid')`.
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

Texto completo en `Rnkr69\LaraChatbot\Llm\SystemPromptBuilder::DEFAULT_DECISION_STRATEGY`.

---

## 3. Cómo activar / desactivar / customizar

Vive en `config/chatbot.php → system_prompt.decision_strategy`:

```php
'system_prompt' => [
    'view'              => 'chatbot::system_prompt',
    'addendum_view'     => env('CHATBOT_SYSTEM_PROMPT_ADDENDUM', null),
    'decision_strategy' => env('CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY', true),
],
```

Tres valores válidos:

| Valor | Efecto |
|---|---|
| `true` (default) | Emite las reglas estándar del package. |
| `false` | Desactiva la sección entera. El LLM decide sin guidance específico. |
| `'view::name'` | Renderiza esa vista Blade en lugar del default. Útil para hosts que quieren un set de reglas custom con su misma estructura. |

### 3.1 Desactivar (no recomendado)

```env
CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY=false
```

Para hosts que no usan page context (chat puro, sin admin) la sección
es ruido. Para cualquier admin web, déjala activa.

### 3.2 Customizar vía vista propia

```env
CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY=chatbot.decision-strategy-custom
```

Publica `resources/views/chatbot/decision-strategy-custom.blade.php`:

```blade
{{-- Reglas de decisión específicas de mi dominio --}}
## Page context — decision strategy

### Cuando el usuario está en /captain/fleet
- Las acciones masivas de aprobación (bulk_approve_missions) las DEBE
  proponer con confirm=true. Nunca auto.
- Si selected_ids está vacío, sugerir al usuario seleccionar primero.

### Cuando el usuario está en /pilot/dashboard
- ...
```

Cuando uses un view custom, **sustituye** las reglas default — no las
extiende. Si quieres añadir reglas SIN perder las default, usa el
mecanismo `system_prompt.addendum_view` en su lugar (ver
[`getting-started.es.md`](getting-started.es.md)).

### 3.3 Combinar reglas: addendum_view para extender

`addendum_view` se concatena al final del system prompt **además** de
las reglas default. Patrón recomendado:

```php
'system_prompt' => [
    'decision_strategy' => true,                                  // reglas default del package
    'addendum_view'     => 'chatbot.host-specific-rules',        // reglas adicionales del host
],
```

Y la vista `host-specific-rules.blade.php` añade matices ("en este host
las acciones financieras siempre requieren confirm doble", etc.).

---

## 4. Patrones E2E

### 4.1 Listing → filtrar grid (no duplicar)

**Usuario** está en `/admin/mission` y escribe:
> "Filtra por destino Marte y status approved"

**Page context** (resumido):
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

**LLM decide (con las reglas activas)**:
1. Mismo entity (`Mission`) y action `list` → page-bound.
2. Mapea labels a values: `Marte → 2`, `approved → "approved"`.
3. Las list views de Backpack no exponen un `<form>`, así que construye la
   querystring y llama `navigate({url: '/admin/mission?destination_planet_id=2&status=approved'})`.
4. Confirma al usuario en chat: "Aplicados los filtros. Ves los resultados arriba."

**Sin las reglas**, el LLM tiende a llamar `list_missions(destination_planet_id=2, status='approved')` y emitir un `render_block` table que duplica la grid nativa.

### 4.2 Listing → otra entidad (chat-bound OK)

**Usuario** está en `/admin/mission` pero escribe:
> "Cuántas naves tengo en mantenimiento?"

**Page context** dice `entity: Mission`, pero la pregunta es sobre `Ship`.

**LLM decide**: entity distinto → chat-bound. Llama `list_ships(status='maintenance')` → render_block stats / table. Correcto.

### 4.3 Detail view → editar registro visible

**Usuario** está en `/admin/mission/25/show`, escribe:
> "Cancela esta"

**Page context**:
```json
{ "crud": { "entity": "Mission", "action": "show", "filters": {"mission_id": 25} } }
```

**LLM decide**: detail view + acción sobre el registro visible → llama directamente `cancel_mission(mission_id=25)` (o el frontend wrapper con confirm). No vuelve a llamar `mission_detail(25)` para repintar la card.

### 4.4 Create form → fill_form

**Usuario** está en `/admin/mission/create`, escribe:
> "Misión Tierra → Marte, departure mañana 8:00, prioridad express"

**Page context** incluye el `crud.form` con el schema completo (campos, options de FK, types).

**LLM decide**:
1. Page action es `create` y hay form schema visible.
2. Resuelve `Tierra → 1`, `Marte → 2` de `options` de los selects.
3. Llama `fill_form(fields=[{name:'origin_planet_id',value:1}, ...])`.
4. El widget muestra el banner confirm; el usuario revisa y submit.

### 4.5 Ambigüedad explícita

**Usuario** está en `/admin/mission`, escribe:
> "Pon mis misiones del último mes en una tabla aquí mismo en el chat"

**LLM** respeta la preferencia explícita ("aquí mismo en el chat") aunque el default fuera page-bound: usa backend tool + render_block. Las reglas tienen una cláusula explícita "Don't re-render in chat unless the user asks for 'in chat' / 'here'".

---

## 5. Tests E2E (cookbook)

Patrón estándar para verificar que el LLM toma las decisiones correctas
en cada combinación de (page_context, user prompt). Lo medimos con
trazas reales de un host de pruebas:

| # | Page | Prompt | Expected |
|---|---|---|---|
| 1 | `/admin/mission` (list) | "filtra status approved + risk high" | `navigate({url: '/admin/mission?status=approved&risk=high'})` |
| 2 | `/dashboard` (no CRUD) | "filtra status approved + risk high" | `list_my_missions(...)` + `render_block` |
| 3 | `/admin/mission/25/show` | "cancela esta" | `cancel_mission(25)` (directo) |
| 4 | `/admin/mission?status=draft` con 8 seleccionados | "aprueba estas" | `approve_missions_bulk(target_ids=[...])` |
| 5 | `/admin/mission` (list) | "pinta una tabla aquí mismo" | `list_my_missions(...)` + `render_block` (respeta override explícito) |

Patrón de test en `tests/Feature/`:

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

(El testing harness `InteractsWithChatbot` que documenta esto se añade en
v1.1.1.)

---

## 6. Debugging — inspeccionar qué reglas ve el LLM

El comando `chatbot:decision-rules:show` imprime las reglas activas:

```
$ php artisan chatbot:decision-rules:show

  Source:  package default
  Length:  6.8 KB

  ----------------------------------------------------------------------
  ## Page context — decision strategy
  ...
  ----------------------------------------------------------------------

  Addendum:  (none)
```

Si el host configuró `decision_strategy='view::name'`, el comando
imprime el render de esa vista en lugar del default. Útil para
diagnosticar "¿por qué el LLM eligió chat-block cuando debería haber
filtrado el grid?" — empieza por ver lo que el LLM realmente está leyendo.

---

## 7. Por qué importa (más allá de la estética)

- **Token economy**: 50 filas en chat block = ~3000 tokens de salida +
  el bloque se persiste en `chatbot_messages` y vuelve al contexto en
  turns futuros. El grid nativo es 0 tokens del LLM.
- **Performance**: render_block con 50 filas estira el shadow DOM del
  widget; modificar filtros de una DataTables existente es
  prácticamente gratis.
- **Discoverability**: el usuario aprende mejor la app cuando el LLM
  "actúa sobre" la UI que ve, en lugar de duplicarla. El LLM se
  convierte en un copiloto sobre la app, no en un sustituto.
- **Consistency**: en turns sucesivos, si el LLM filtró el grid en el
  turn 1, el turn 2 puede referirse a "las 50 que tenemos en pantalla"
  sin repintar nada. La conversación queda más natural.

---

## 8. Referencias

- System prompt builder: `src/Llm/SystemPromptBuilder.php`
- Default rules: `SystemPromptBuilder::DEFAULT_DECISION_STRATEGY` constante
- Config key: `config/chatbot.php → system_prompt.decision_strategy`
- Page context provider Backpack: [`integrations/backpack.es.md`](integrations/backpack.es.md)
- Custom forms (no-Backpack): [`integrations/custom-forms.es.md`](integrations/custom-forms.es.md)
- Comando debug: `php artisan chatbot:decision-rules:show`
