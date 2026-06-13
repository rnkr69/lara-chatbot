# Confirmation flow — `auto`, `confirm`, `manual`

*[English](confirmation-flow.md) · Español*

> Niveles de confirmación de **frontend tools** y su ciclo de vida en el
> paquete `rnkr69/lara-chatbot`. Backend tools en v1 sólo soportan `auto`.

## TL;DR

| Nivel       | ¿Quién decide?           | Persistencia               | Resultado al LLM       |
|-------------|--------------------------|----------------------------|------------------------|
| `auto`      | Nadie — corre directo    | Ninguna                    | `queued + action_id`   |
| `confirm`   | Usuario aprueba/rechaza  | `chatbot_pending_actions`  | `awaiting_user`        |
| `manual`    | Usuario marca como hecha | `chatbot_pending_actions`  | `awaiting_user`        |

`confirm` es para acciones que **el widget ejecuta** (navegación crítica,
descarga firmada, llamada destructiva). `manual` es para acciones que
**el usuario hace fuera del chatbot** (firmar un documento físico, llamar a
alguien, etc.) y luego marca como hechas/no-hechas.

## Configuración

```php
// config/chatbot.php
'limits' => [
    // ...
    'pending_action_ttl' => [
        'confirm' => 600,     //  10 min — aprobación rápida.
        'manual'  => 86_400,  //  24 h  — acción humana real.
    ],
    'pending_actions_in_prompt' => 10, // Tope de la sección ## Pending actions.
],
```

El comando `chatbot:cleanup-actions` marca como `expired` los `pending`
caducados. Schedule recomendada (host):

```php
// app/Console/Kernel.php
$schedule->command('chatbot:cleanup-actions')->everyFiveMinutes();
```

## Ciclo de vida — `confirm`

```
┌──────────┐                                                ┌───────────┐
│ FrontendTool ───── ChatService::onToolCall ────────▶ pending          │
│ confirm  │                                          (chatbot_pending_actions)
└──────────┘                                                └─────┬─────┘
                                                                  │
   widget recibe `frontend_action`,                               │
   renderiza banner Accept/Reject                                 │
                                                                  ▼
                          ┌──────────────────┐         ┌──────────────────┐
            POST {accept:false}              POST {accept:true}
            /actions/{id}/confirm            /actions/{id}/confirm
                          │                                   │
                          ▼                                   ▼
                     rejected (terminal)              confirmed (intermedio)
                          │                                   │
                          │                                   │ widget ejecuta
                          │                                   │ la primitiva localmente
                          │                                   │
                          │                            POST {accept:true, result:...}
                          │                                   │
                          │                                   ▼
                          │                            executed (terminal)
                          ▼                                   ▼
                            siguiente turno → ## Pending actions

                                  El LLM lee:
                                  - [REJECTED] tool=confirm_dialog ...
                                  - [PENDING]  tool=...  (si quedan abiertos)
                                  - [EXPIRED]  tool=...  (si el cron pasó)
```

### Atajo: `accept + result` en una sola llamada

El widget puede combinar la aceptación y la ejecución en una única llamada
si tiene el resultado disponible al instante:

```http
POST /chatbot/actions/abc-123/confirm
Content-Type: application/json

{ "accept": true, "result": { "ok": true, "downloaded_bytes": 8421 } }
```

→ row pasa de `pending` directamente a `executed`. El paso intermedio
`confirmed` se omite.

## Ciclo de vida — `manual`

Mismo endpoint, mismo body. El widget renderiza un banner con botones
"Mark as done" y "Mark as not done":

- "Mark as done"     → `POST {accept: true, result: {done: true}}` → `executed`.
- "Mark as not done" → `POST {accept: false}` → `rejected`.

No hay paso intermedio `confirmed`: el usuario reporta el outcome final
directamente.

## Endpoint `POST /chatbot/actions/{action}/confirm`

| Campo  | Tipo   | Notas                                                       |
|--------|--------|-------------------------------------------------------------|
| `accept` | bool   | requerido                                                   |
| `result` | array? | opcional; si llega con `accept=true`, fuerza `executed`     |

Path param `{action}` es el `action_id` (UUID) que viaja en el evento SSE
`frontend_action`.

### Códigos de respuesta

| Código | Cuándo                                                           |
|--------|------------------------------------------------------------------|
| 200    | Transición OK. `data` contiene el `PendingActionResource` actualizado. |
| 401    | Sin sesión (middleware `auth`).                                  |
| 404    | `action_id` desconocido **o** pertenece a otro usuario (404-no-403). |
| 409    | Estado terminal o expirado. `pending_action` contiene el row congelado. |
| 422    | Body mal formado (`accept` falta o no es bool).                  |

### Idempotencia

Los estados terminales (`rejected`, `executed`, `expired`) **no** transicionan;
una segunda llamada devuelve `409 Conflict`. La excepción es la transición
`pending → confirmed → executed` para el flujo de dos pasos.

## Reincorporación al system prompt — `## Pending actions`

`SystemPromptBuilder` añade programáticamente esta sección cuando hay rows
relevantes en la conversación:

```text
## Pending actions
The following frontend actions you proposed are awaiting/were resolved by the user.
Use this to avoid re-proposing rejected/expired actions and to acknowledge pending ones to the user:
- [REJECTED] tool=confirm_dialog action_id=abc-123 args={"message":"Send the email?"}
- [PENDING]  tool=open_modal     action_id=def-456 args={"id":42}
- [EXPIRED]  tool=download_file  action_id=ghi-789 args={"filename":"report.pdf"}
```

Sólo se vuelcan los estados que aportan información para el siguiente turno:

- `pending`  → "espero respuesta del usuario";
- `rejected` → "el usuario me dijo que no";
- `expired`  → "se acabó el tiempo, decide si reintentar".

Los `confirmed` y `executed` se omiten — son outcomes positivos cuyo efecto
ya está en el mundo (la primitiva corrió). El listado se trunca a
`chatbot.limits.pending_actions_in_prompt` entradas, ordenadas por id desc.

## Limitaciones de v1

- **Backend tools NO soportan `confirm`/`manual`**. Filtrado con `Log::warning`
  accionable. Para un host que necesite confirmación dura sobre una acción de
  backend: implementar como frontend tool que dispara el backend al confirmarse.
- **Una pendiente acción no bloquea el siguiente turno** — el usuario
  puede seguir conversando con el LLM mientras hay rows `pending`. El LLM
  se entera del outcome al ver la sección `## Pending actions` en el
  prompt del siguiente turno.
- **Step-up auth (`reauth`)** queda en backlog v1.1.

## Ejemplo end-to-end

### Turno 1

```
Usuario: ¿puedes enviar el email de bienvenida?
LLM:     [tool_call] confirm_dialog({message: "Send welcome email?"})
ChatService persiste pending action UUID=abc.
Devuelve `awaiting_user` al LLM.
LLM:     "Te confirmo cuando aceptes."
```

Widget recibe `frontend_action {tool: confirm_dialog, confirmation: confirm,
action_id: abc}` → renderiza banner Accept/Reject bajo el mensaje del
asistente.

### Usuario rechaza

```
POST /chatbot/actions/abc/confirm  {accept: false, result: {reason: "Mañana"}}
```

Row pasa a `rejected`. Banner se elimina. Toast `Rejected: confirm_dialog`.

### Turno 2

```
Usuario: ¿qué pasó con el email?
LLM (lee el system prompt con):
  ## Pending actions
  - [REJECTED] tool=confirm_dialog action_id=abc args={"message":"Send welcome email?"}

LLM: "Lo dejaste para mañana. Avísame cuando quieras enviarlo."
```

## Tests de referencia

- **Backend** (Pest):
  - `tests/Feature/Services/PendingActionStoreTest.php` — transiciones del store.
  - `tests/Feature/Http/ConfirmActionControllerTest.php` — endpoint REST.
  - `tests/Feature/Console/CleanupActionsCommandTest.php` — comando `chatbot:cleanup-actions`.
- **Frontend** (Vitest):
  - `tests/js/confirm.test.ts` — banner UI + `postConfirm` + `deriveConfirmUrl`.
  - `tests/js/widget.test.ts` (sección "confirm/manual banner routing").
