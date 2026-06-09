# Roadmap de implementación: Paquete Laravel Chatbot multi-versión

> Documento de trabajo destinado a guiar la construcción del paquete por épicas. Pensado para ser ejecutado de forma incremental por un agente (Claude Code).

---

## 0. Visión general

### 0.1 Objetivo
Construir un paquete Laravel reutilizable, instalable como dependencia Composer privada, que añade a cualquier proyecto host (Laravel 11 o 12) un asistente conversacional con LLM capaz de:

1. Conversar con el usuario respetando sus permisos y datos accesibles.
2. Ejecutar acciones en el **backend** del host (consultas, creaciones, etc.) declaradas como *Backend Tools*.
3. Ejecutar acciones en el **frontend** del host (navegar, rellenar un formulario, resaltar elementos, abrir un modal, etc.) declaradas como *Frontend Tools*.
4. Integrarse con servidores MCP externos como fuente adicional de tools, sin acoplarse a ellos.

### 0.2 Decisiones tomadas
| Eje | Decisión |
|---|---|
| Capa LLM | `prism-php/prism` (multi-provider) |
| Bridge MCP | `prism-php/relay` (cliente MCP oficial de Prism) |
| Frontend del widget | Web Component vanilla (agnóstico de framework) |
| Modos de presentación | Widget lateral **+** página dedicada compartiendo estado |
| Tipos de proyecto host | MPA (Blade) **y** SPA (Inertia/Livewire SPA) con detección automática |
| Tools de FE | Convenciones `data-chatbot-*` + escape hatch JS (`window.Chatbot.registerTool`) |
| Contexto de página | Declarativo (meta tag o `window.Chatbot.setPageContext`) |
| Renderizado de respuestas | Markdown + bloques tipados con renderers registrables |
| Confirmación de tools | Tres niveles: `auto` / `confirm` / `manual` |
| Streaming | SSE sobre endpoint Laravel (sin Reverb/WebSockets) |
| Persistencia | Modelos `Conversation` y `Message`, asociación al usuario vía `morphTo` |
| Compatibilidad | PHP 8.2+, Laravel 11 y 12 |

### 0.3 Restricciones y principios
- **No requerir** Spatie Permission ni ningún paquete de autorización: detección por *autodiscovery* y adaptador opcional.
- **No acoplar** a Inertia, Livewire, Vue ni React.
- **Genérico**: las tools concretas las define cada proyecto host. El paquete sólo trae primitivas y contratos.
- **Seguro por defecto**: ninguna tool ejecuta nada sin pasar por el resolutor de autorización; ningún dato del host se devuelve sin filtrado por scope.
- **Versionable**: SemVer, tags en git privado, releases acompañadas de CHANGELOG.

---

## 1. Arquitectura

### 1.1 Capas
```
┌─────────────────────────────────────────────────────────┐
│ Host project (Laravel 11/12)                            │
│  - Vistas Blade / SPA                                   │
│  - HTML con atributos data-chatbot-*                    │
│  - Backend Tools propias (clases PHP)                   │
│  - Frontend Tools propias registradas en JS             │
│  - Page Context declarado                               │
└────────────┬───────────────────┬────────────────────────┘
             │                   │
             │ HTTP/SSE          │ Custom Events DOM
             ▼                   ▼
┌─────────────────────────────────────────────────────────┐
│ Paquete Chatbot                                          │
│  ┌─ Web Component (chat-widget)                          │
│  │   · UI · streaming reader · block renderer · router   │
│  └─                                                      │
│  ┌─ HTTP Layer (Controllers + Middlewares)               │
│  │   · /chatbot/stream  · /chatbot/conversations  ...    │
│  └─                                                      │
│  ┌─ ChatService (orquestador)                            │
│  │   · construye mensajes · system prompt · tools        │
│  │   · invoca Prism · intercepta tool events             │
│  └─                                                      │
│  ┌─ Tool Registry                                        │
│  │   · BackendTools (PHP) · FrontendTools (PHP shim)     │
│  │   · McpBridge (relay)                                 │
│  └─                                                      │
│  ┌─ AuthorizationLayer                                   │
│  │   · GateResolver  · SpatieResolver  · CustomResolver  │
│  │   · ScopeResolver (self/team/all)                     │
│  └─                                                      │
│  ┌─ Persistence (Eloquent)                               │
│  │   · Conversation · Message                            │
│  └─                                                      │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│ Prism PHP                                                │
│   · Anthropic · OpenAI · Ollama · Gemini · Mistral ...   │
│   · Streaming · Tool calling · Embeddings                │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│ Relay (cliente MCP) → Servidores MCP externos opcionales │
└─────────────────────────────────────────────────────────┘
```

### 1.2 Flujo: mensaje de texto puro
1. Usuario escribe en el widget → `POST /chatbot/stream` con `{conversation_id, message, page_context}`.
2. `ChatController` valida, recupera la conversación, persiste el mensaje del usuario.
3. `ChatService` construye system prompt (Blade view) + historial + page_context + lista de tools autorizadas para el usuario.
4. Llama a `Prism::text()->withTools([...])->asStream()`.
5. Itera el generator, traduce cada chunk a evento SSE (`event: text`, `event: done`, etc.).
6. Persiste el mensaje del asistente al cerrar el stream.

### 1.3 Flujo: tool call backend
1. Igual hasta paso 4.
2. Prism detecta tool call, ejecuta la tool PHP de forma síncrona dentro del mismo step (Prism gestiona el loop con `withMaxSteps`).
3. La tool se ejecuta SOLO si pasa autorización + scope.
4. Resultado vuelve al LLM, sigue generando.
5. Frontend recibe el evento `tool_call` (informativo) y luego `text` con la respuesta del LLM.

### 1.4 Flujo: tool call frontend
1. El LLM llama a una *Frontend Tool* (ej. `navigate`).
2. La Frontend Tool en backend es un *shim*: valida args, autoriza, y devuelve `{status: 'queued', action_id}`.
3. **Antes** de devolver el resultado al LLM, el `ChatService` emite un chunk SSE `event: frontend_action` con `{tool, args, action_id, confirmation: 'auto'|'confirm'|'manual'}`.
4. El widget recibe el evento y:
   - Si `auto`: ejecuta la acción ya.
   - Si `confirm`: muestra UI de confirmación, espera click.
   - Si `manual`: renderiza un botón en el chat que dispara la acción al pulsarse.
5. Al LLM se le devuelve "queued" como resultado para que pueda continuar la conversación coherentemente.

### 1.5 Flujo: error de autorización
- Si el usuario no puede ejecutar la tool, el handler devuelve un `ToolResult` con `{error: 'unauthorized', reason: '...'}`. El LLM lo recibe como contexto y responde algo como "no tienes permiso para X".
- El paquete loguea el intento.

---

## 2. Modelo de autorización

Esta es la parte más sensible y se aplica de forma transversal. Hay **tres dimensiones** que se combinan:

### 2.1 Permiso (¿puede invocar esta tool?)
Cada tool declara los permisos que requiere.
```
'permissions' => ['invoices.read']
```
La resolución va en cascada:
1. Si `Spatie\Permission\PermissionServiceProvider` está registrado → `SpatieAuthorizer::check($user, $perms)`.
2. Si el host ha registrado un *Custom Authorizer* en el config → ese.
3. Fallback → `Gate::allows($perm)` para cada permiso.

> **Importante:** el paquete NO añade `spatie/laravel-permission` al `composer.json`. Sólo lo detecta si está presente.

### 2.2 Scope de datos (¿qué subconjunto de registros puede ver?)
Tres niveles estandarizados (`AccessScope` enum):
- `self` → sólo registros propios del usuario.
- `team` → propios + los de los miembros de su equipo (si es manager).
- `all` → sin restricción.

Cada tool declara su `defaultScope` y opcionalmente un `maxScope` por rol. Ejemplo:
- Tool `list_invoices`:
  - rol `employee` → scope `self`
  - rol `manager` → scope `team`
  - rol `admin` → scope `all`

El paquete provee una interfaz `ScopeResolver` que el host implementa una vez:
```
resolveAccessibleUserIds(User $user, AccessScope $scope): array
```
Esto desacopla la implementación concreta de "manager → equipo" del paquete. Cada proyecto la implementa según sus tablas (`teams`, `team_user`, columna `manager_id`, etc.).

Las tools que devuelven datos del host están **obligadas** a aplicar el filtro:
```
$query->whereIn('user_id', $accessibleIds)
```
El contrato `BackendTool` provee un helper `accessibleUserIds()` que internamente llama al `ScopeResolver`.

### 2.3 Ownership puntual (¿puede ver/tocar este registro concreto?)
Para tools que reciben un ID (`get_invoice(id: 42)`), además del scope se aplica una verificación final con la *Policy* del host vía `Gate::authorize('view', $invoice)`. Si no existe policy, el contrato exige al dev declarar un `verifyOwnership(User, Model): bool`.

### 2.4 Resumen del flujo de autorización por tool
```
[invocación de tool]
    ↓
[1] permission check (Spatie | Custom | Gate)
    ↓ ok
[2] scope resolution → accessibleUserIds
    ↓
[3] tool ejecuta query filtrada por accessibleUserIds
    ↓
[4] si la tool toca un registro concreto → policy/Gate::authorize
    ↓
[resultado al LLM]
```

---

## 3. Convenciones

### 3.1 Atributos HTML (`data-chatbot-*`)
| Atributo | Uso |
|---|---|
| `data-chatbot-form="<id>"` | Marca un `<form>` referible por id lógico |
| `data-chatbot-field="<name>"` | Marca un input rellenable |
| `data-chatbot-action="submit\|reset"` | Botón actuable |
| `data-chatbot-target="<id>"` | Cualquier elemento referible (card, sección…) |
| `data-chatbot-context='{"key":...}'` | JSON de contexto adjunto a un elemento |
| `data-chatbot-disable` | Excluye un elemento del scope del bot |

### 3.2 Page Context API (JS)
- Meta tag declarativo:
  `<meta name="chatbot:context" content='{"route":"invoices.index","filters":{...}}'>`
- API imperativa para SPA:
  - `window.Chatbot.setPageContext(obj)` (merge superficial)
  - `window.Chatbot.clearPageContext()`
  - `window.Chatbot.registerTool(name, handler)`
  - `window.Chatbot.registerBlockRenderer(type, renderer)`

### 3.3 Tipos de bloque en el chat
| Tipo | Datos esperados |
|---|---|
| `text` | markdown |
| `card` | `{title, body, actions[]}` |
| `table` | `{columns[], rows[], rowActions[]}` |
| `list` | `{items[]}` |
| `chart` | `{kind: 'line'\|'bar'\|'pie', data}` (renderer mínimo, ampliable por host) |
| `actions` | `{buttons[{label, tool, args, confirmation}]}` |
| `<custom>` | el host registra su renderer |

### 3.4 Eventos SSE emitidos por `/chatbot/stream`
| Event | Data |
|---|---|
| `text` | chunk de texto markdown |
| `block` | `{type, data}` bloque tipado |
| `tool_call` | `{name, args}` informativo |
| `tool_result` | `{name, ok, summary}` informativo |
| `frontend_action` | `{tool, args, action_id, confirmation}` |
| `error` | `{message, code}` |
| `done` | `{message_id, usage}` |

### 3.5 Rutas HTTP del paquete
| Método | Ruta | Acción |
|---|---|---|
| POST | `/chatbot/stream` | Envía mensaje y devuelve SSE |
| GET | `/chatbot/conversations` | Lista paginada de conversaciones del usuario |
| POST | `/chatbot/conversations` | Crea conversación |
| GET | `/chatbot/conversations/{id}` | Detalle + mensajes |
| DELETE | `/chatbot/conversations/{id}` | Borrado lógico |
| POST | `/chatbot/actions/{id}/confirm` | Confirma una acción `confirm`/`manual` |
| GET | `/chatbot` | Vista de página dedicada (publishable) |
| GET | `/chatbot/widget.js` | Asset del Web Component |

Todas las rutas viven bajo un middleware group configurable (`auth` por defecto).

### 3.6 Naming y namespaces
- Namespace raíz del paquete: a definir por la empresa (`Empresa\Chatbot`). Se documenta como variable a sustituir.
- Prefijo de migraciones: `chatbot_`.
- Prefijo de config: `chatbot.`.
- Prefijo de rutas nombradas: `chatbot.*`.
- Prefijo de eventos: `chatbot.*`.
- Prefijo de tabla en BBDD: configurable, default `chatbot_`.

---

## 4. Estructura del paquete

```
laravel-chatbot/
├── composer.json
├── README.md
├── CHANGELOG.md
├── LICENSE
├── config/
│   └── chatbot.php
├── database/
│   ├── migrations/
│   │   ├── xxxx_create_chatbot_conversations_table.php
│   │   └── xxxx_create_chatbot_messages_table.php
│   └── factories/
├── routes/
│   └── chatbot.php
├── resources/
│   ├── js/                  # Fuente del Web Component (TS)
│   ├── views/               # Vistas publicables
│   └── lang/                # i18n base
├── public-build/            # Assets compilados distribuidos
│   └── chatbot-widget.js
├── src/
│   ├── ChatbotServiceProvider.php
│   ├── Facades/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   └── Middleware/
│   ├── Models/
│   ├── Services/
│   ├── Tools/
│   │   ├── Contracts/
│   │   ├── Backend/         # Primitivas (ninguna en el core; sólo base class)
│   │   └── Frontend/        # Primitivas core (navigate, fill_form, …)
│   ├── Authorization/
│   ├── Streaming/
│   ├── Mcp/
│   ├── Events/
│   └── Support/
├── examples/                # Tools de ejemplo, no se cargan automáticamente
└── tests/
```

---

## 5. Épicas (paso a paso)

> Cada épica incluye: **Objetivo**, **Alcance**, **Entregables**, **Criterios de aceptación (DoD)**, **Dependencias**.
> El orden de las épicas es el orden recomendado de implementación.

---

### Fase 0 — Fundamentos

#### E01 · Bootstrap del paquete
**Objetivo:** dejar el paquete instalable en un Laravel 11/12 vacío y reconocido por el framework.
**Alcance:**
- `composer.json` con autoload PSR-4, dependencias mínimas (`prism-php/prism`, `illuminate/contracts`, `illuminate/support`).
- `ChatbotServiceProvider` con `register()` y `boot()` vacíos pero registrando: config merge, rutas, vistas, migraciones, traducciones, comando de instalación.
- Auto-discovery en `extra.laravel.providers`.
- README mínimo con badge de versión y ejemplo de instalación desde repo privado.
**Entregables:**
- `composer.json`, `ChatbotServiceProvider.php`, README esqueleto.
**DoD:**
- `composer require empresa/chatbot:dev-main` en un Laravel 11 limpio carga el provider sin errores.
- Igual en Laravel 12.
**Dependencias:** ninguna.

#### E02 · Configuración y publicación de assets
**Objetivo:** exponer todos los puntos de personalización del paquete vía config y `vendor:publish`.
**Alcance:**
- `config/chatbot.php` con secciones: `provider`, `model`, `system_prompt`, `route` (prefix, middleware, domain), `persistence` (driver, prefix, soft delete), `authorization` (resolver, scope_resolver, default_scope), `tools` (auto_discover, paths), `mcp.servers`, `widget` (theme, position, default_open), `limits` (max_steps, max_tokens, rate_limit).
- Tags de publicación: `chatbot-config`, `chatbot-migrations`, `chatbot-views`, `chatbot-assets`, `chatbot-lang`, `chatbot-prompts`.
**DoD:**
- `php artisan vendor:publish --tag=chatbot-config` deja el archivo en `config/chatbot.php` del host.
- Cada tag publica sólo lo suyo.
- Documentado en README.
**Dependencias:** E01.

#### E03 · Persistencia
**Objetivo:** modelar conversaciones y mensajes con relación polimórfica al usuario del host.
**Alcance:**
- Migración `chatbot_conversations` (`id`, `user_type`, `user_id` (morph), `title`, `metadata` json, timestamps, soft deletes).
- Migración `chatbot_messages` (`id`, `conversation_id` fk, `role` enum [user, assistant, tool, system], `content` json (bloques tipados), `tool_calls` json nullable, `tool_results` json nullable, `tokens_in`, `tokens_out`, timestamps).
- Modelos `Conversation` y `Message` con relaciones, scopes (`forUser`), `casts` y un `HasFactory`.
- Trait opcional `HasChatbotConversations` para el modelo `User` del host (relación inversa).
**DoD:**
- Migraciones idempotentes y revertibles.
- Tests unitarios de relaciones y `forUser` scope.
**Dependencias:** E02.

#### E04 · Capa de autorización
**Objetivo:** implementar el modelo de la sección 2 sin acoplar a Spatie.
**Alcance:**
- Enum `AccessScope { self, team, all }`.
- Interfaz `Authorizer` con `check(User $u, array $perms): bool`.
  - `GateAuthorizer` (default).
  - `SpatieAuthorizer` (sólo si la clase de Spatie existe → autodiscovery con `class_exists`).
  - `CustomAuthorizer` (resoluble vía config `chatbot.authorization.resolver = MyAuthorizer::class`).
- Interfaz `ScopeResolver` con `resolveAccessibleUserIds(User $u, AccessScope $s): array<int>`.
  - Implementación `NullScopeResolver` que devuelve `[user.id]` para `self` y lanza para `team|all` (forzando al host a implementar el suyo si quiere usar esos scopes).
- Trait `AuthorizesToolAccess` que las clases base de tools usan para componer permission + scope + ownership en una sola llamada.
- Excepción `ToolUnauthorizedException` (mensaje seguro, sin filtrar internals).
- Comando `php artisan chatbot:make:scope-resolver` que stubeará una clase en el host.
**DoD:**
- Tests con `Authorizer` (los tres modos), `ScopeResolver` (null y custom), trait integrado.
- Si se setea `provider = spatie` en config y Spatie no está instalado → exception con mensaje claro al boot.
**Dependencias:** E03.

---

### Fase 1 — Capa de chat y backend tools

#### E05 · Integración Prism
**Objetivo:** envolver Prism para que `ChatService` no llame directamente al SDK.
**Alcance:**
- Clase `LlmGateway` que expone:
  - `streamChat(messages, tools, options): Generator`
  - `chat(messages, tools, options): Response` (no streaming, fallback)
- Resolución de provider/model desde config con override por conversación (campo `metadata.provider/model`).
- Construcción del system prompt: si `chatbot.system_prompt.view` existe, render via `view()`; si no, string literal. Inyección automática de `page_context` y `user_summary` (id, name, roles si Spatie).
- Helper `withTools()` que mapea las tools del registro al formato de Prism (`Prism\Prism\Tool`).
- Manejo de errores específico (rate limit, auth, timeout) con `LlmException` envolvente.
**DoD:**
- Tests con `Prism::fake()` cubriendo: chat simple, con tool call, error de provider.
- `php artisan chatbot:test-connection` (comando) hace una llamada de "ping" al LLM y reporta éxito/fallo.
**Dependencias:** E02.

#### E06 · Contrato y registro de Backend Tools
**Objetivo:** que el host pueda registrar tools de backend con un contrato claro.
**Alcance:**
- Interfaz `BackendTool` con métodos:
  - `name(): string`
  - `description(): string`
  - `parameters(): array` (JSON Schema)
  - `permissions(): array`
  - `defaultScope(): AccessScope`
  - `confirmation(): ConfirmationLevel` (default `auto`)
  - `handle(array $args, ToolContext $ctx): ToolResult`
- Clase abstracta `BaseBackendTool` que aporta:
  - Validación de args contra el JSON Schema (vía Laravel Validator interno).
  - Llamada automática a `Authorizer` y `ScopeResolver` antes de `handle()`.
  - Helper `accessibleUserIds()` y `accessibleQuery(Builder)`.
- `ToolRegistry` con métodos `register(class)`, `registerMany(array)`, `forUser(User): array<BackendTool>` (filtra por permisos).
- Auto-discovery configurable: si `chatbot.tools.auto_discover` es `true`, recorre `app/Chatbot/Tools` y registra clases que implementen `BackendTool`.
- Comando `php artisan chatbot:make:tool {Name}` con stubs para tool de lectura y de escritura.
**DoD:**
- Test: una tool registrada aparece en `forUser` solo si pasa `permissions()`.
- Test: una tool con `defaultScope = team` recibe `accessibleUserIds` correctos.
- Test: una tool con args inválidos devuelve `ToolResult::error('validation', ...)` sin invocar `handle()`.
**Dependencias:** E04, E05.

#### E07 · Bridge MCP
**Objetivo:** poder enchufar servidores MCP externos como fuente extra de tools, opcional.
**Alcance:**
- Lectura de `chatbot.mcp.servers[]` (cada entrada: `name`, `transport: stdio|http`, `command|url`, `auth`, `enabled`).
- Wrapper `McpToolBridge` que usa `prism-php/relay` para listar tools de los servers activos y exponerlas como `BackendTool` "remotas" (con un `name` prefijado: `mcp.<server>.<tool>`).
- Las tools MCP **también** pasan por el `Authorizer`: cada server puede declarar permisos requeridos en config.
- Cacheo de la lista de tools MCP por server con TTL configurable.
- Si `prism-php/relay` no está instalado, el bridge se desactiva con warning y todo lo demás funciona.
**DoD:**
- Test con un server MCP fake (mock de Relay) que expone una tool y se ve aparecer en el registro.
- Si Relay falta, `php artisan chatbot:tools:list` lo señala pero no rompe.
**Dependencias:** E06.

#### E08 · ChatService (orquestador)
**Objetivo:** centralizar el ciclo de vida de un mensaje y producir el stream de eventos SSE.
**Alcance:**
- `ChatService::handle(Conversation $c, string $userMessage, array $pageContext): Generator<SseEvent>` que:
  1. Persiste el mensaje del usuario.
  2. Construye historial (limita a últimos N mensajes según config).
  3. Resuelve tools del usuario via `ToolRegistry::forUser`.
  4. Llama a `LlmGateway::streamChat`.
  5. Itera y traduce cada chunk Prism a uno o varios `SseEvent`. Detecta:
     - `TextDelta` → `event: text`.
     - `ToolCall` cuyo nombre coincida con una `FrontendTool` → emite `event: frontend_action` y devuelve "queued" como result.
     - `ToolCall` backend → deja a Prism ejecutar y emite `tool_call` + `tool_result` informativos.
     - `BlockEvent` (custom marker) → `event: block` (ver E15).
  6. Persiste el mensaje del assistant al cierre con `tool_calls`/`tool_results`.
- `ConfirmationLevel::confirm/manual` para frontend tools: en lugar de devolver "queued" inmediato al LLM, emite `frontend_action` con `confirmation` y mete la acción en una tabla pivote `chatbot_pending_actions` con expiración (ver E16).
**DoD:**
- Test integrado con `Prism::fake` que cubre los tres tipos de chunk.
- Test que verifica orden: una `frontend_action` SE EMITE ANTES del siguiente `text`.
**Dependencias:** E06, E07.

#### E09 · Endpoint SSE de chat
**Objetivo:** exponer `/chatbot/stream` consumible por el Web Component.
**Alcance:**
- `ChatController@stream`: valida request (`SendMessageRequest`), recupera/crea conversación, abre `response()->stream(...)`, headers SSE estándar (`text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`).
- Gestión de cierre del cliente (detectar `connection_aborted()`).
- Rate limit por usuario configurable (`chatbot.limits.rate_limit`).
- Middleware del grupo configurable.
**DoD:**
- E2E con Pest: cliente HTTP que hace POST y recibe los eventos en orden.
- Test de cierre: si el cliente cierra, no se sigue gastando tokens.
**Dependencias:** E08.

#### E10 · Endpoint de conversaciones
**Objetivo:** CRUD básico para listar/crear/borrar conversaciones, asociadas siempre al usuario actual.
**Alcance:**
- `ConversationController` con `index/store/show/destroy`.
- `index` paginado con búsqueda por título.
- `show` devuelve mensajes paginados (cursor, último primero).
- Todas las acciones aplican `forUser` scope.
**DoD:**
- Tests de policy: un usuario no puede ver/borrar conversaciones de otro.
**Dependencias:** E03.

---

### Fase 2 — Frontend tools y widget

#### E11 · Contrato de Frontend Tools y primitivas core
**Objetivo:** definir el contrato server-side de FE tools y entregar las primitivas del catálogo recomendado.
**Alcance:**
- Interfaz `FrontendTool extends BackendTool` (mismo contrato pero `handle()` no toca DB; sólo valida args y devuelve "queued").
- Clase `BaseFrontendTool` que automatiza el "shim".
- Primitivas core publicadas en `src/Tools/Frontend/`:
  - `NavigateTool` (`url` o `route` + `params`)
  - `ToggleVisibilityTool` (`selector`, `action`)
  - `FillFormTool` (`selector` o `form_id`, `fields[]`, `submit`)
  - `ShowToastTool` (`message`, `level`)
  - `OpenModalTool` (`title`, `block`, `actions[]`)
  - `RenderBlockTool` (emite un `event: block`)
  - `InvokeHostActionTool` (`action_name`, `args`) — escape hatch.
  - `DownloadFileTool` (`url_or_disk_path`, `filename?`, `mime?`, `expires_in?`)
  - *(v1.1.2: `HighlightTool` retirada — finding #15.)*
- Cada primitiva con su `description` cuidadosamente redactada (importante para que el LLM las use bien).
- `confirmation` por defecto:
  - `auto`: navigate, toggle, toast, render_block, download_file.
  - `confirm`: fill_form (cuando `submit=true`), open_modal con acciones destructivas.
  - `manual`: invoke_host_action (siempre, salvo override del host).
**DoD:**
- Cada primitiva con test unitario de validación de args.
- Documento `FRONTEND_TOOLS.md` con tabla y ejemplos de uso desde el LLM.
**Dependencias:** E06, E08.

#### E12 · Web Component (estructura base)
**Objetivo:** dejar `<chatbot-widget>` registrable y montable en cualquier página, con UI mínima.
**Alcance:**
- TypeScript en `resources/js/`, build a un único bundle ES module sin dependencias externas en runtime.
- Estructura interna: shadow DOM, slots para header/footer, estilos encapsulados.
- Estado: `closed | minimized | open | fullscreen`.
- Atributos: `data-endpoint`, `data-conversation-id`, `data-position` (left|right), `data-default-open`.
- API global `window.Chatbot`:
  - `open()`, `close()`, `toggle()`, `setPageContext()`, `clearPageContext()`, `registerTool()`, `registerBlockRenderer()`, `setUser(token)`.
- Lector SSE robusto (con reconexión exponencial, no `EventSource` por necesitar POST → fetch + ReadableStream).
- Renderer básico de markdown (lib pequeña tipo `marked` o propia simple) y de bloques `text` y `actions`. El resto en E15.
- Build script (Vite o esbuild) que produzca `public-build/chatbot-widget.js`.
**DoD:**
- Página HTML estática con sólo el script funciona contra un backend mock y muestra texto en stream.
- Tamaño del bundle < 80KB gzip.
**Dependencias:** E09.

#### E13 · Detección SPA/MPA y persistencia de estado
**Objetivo:** que el widget sobreviva navegaciones y se comporte bien en ambos mundos.
**Alcance:**
- Detección heurística:
  - SPA si `window.Inertia`, o eventos `livewire:navigated`, o `history.pushState` consistente, o el host marca `<meta name="chatbot:mode" content="spa">`.
  - MPA por defecto.
- En SPA: el widget se inserta una vez y permanece; las primitivas de navegación usan el adaptador SPA (Inertia visit, Livewire navigate, o `history.pushState` + `popstate`).
- En MPA: el widget guarda `{conversationId, isOpen, draft}` en `sessionStorage` y se rehidrata en cada page load. La primitiva `navigate` hace `window.location.assign`.
- Adaptadores de navegación pluggables: `window.Chatbot.registerNavigator(fn)`.
**DoD:**
- Cypress/Playwright: caso MPA con 3 page loads consecutivos manteniendo conversación.
- Caso SPA con Inertia mock manteniendo el widget.
**Dependencias:** E12.

#### E14 · Page Context API
**Objetivo:** que el LLM sepa qué pantalla está viendo el usuario.
**Alcance:**
- Lectura inicial del meta tag `chatbot:context`.
- API `setPageContext(obj)` con merge superficial.
- Hook automático en SPA: en cada navegación re-leer meta + emitir evento `chatbot:context-changed`.
- Envío del page_context como campo en cada `POST /chatbot/stream`.
- En backend, el `ChatService` lo inyecta en el system prompt bajo una sección `## Current page` con campos sanitizados (sólo strings/números/booleans/arrays simples; nada de HTML).
- Sanitización en backend de tamaño máximo (config `chatbot.limits.page_context_kb`, default 4).
**DoD:**
- Test: cambiar page_context entre dos mensajes hace que el system prompt cambie.
- Test de truncado por tamaño.
**Dependencias:** E08, E12.

#### E15 · Renderizado de bloques tipados
**Objetivo:** mostrar respuestas ricas dentro del chat.
**Alcance:**
- Renderers base en el widget para: `text`, `card`, `table`, `list`, `actions`, `chart` (mínimo, datos simples).
- API `registerBlockRenderer(type, fn)` para que el host añada o sobrescriba.
- Slots HTML del host: si existe `<template data-chatbot-block-template="card">`, el widget la usa para clonar/rellenar el bloque (alternativa a registrar un renderer JS).
- El backend emite bloques mediante una "Block Tool" (`RenderBlockTool` de E11) o mediante el LLM enviando `<block type="card">{...}</block>` que el `ChatService` parsea.
**DoD:**
- Test de renderer base por cada tipo.
- Demo: el LLM responde "aquí los pedidos" + tabla con 3 filas.
**Dependencias:** E12.

#### E16 · Niveles de confirmación
**Objetivo:** soportar `auto`, `confirm` y `manual` para frontend tools (backend tools quedan en `auto` para MVP, doc explícita).
**Alcance:**
- Tabla `chatbot_pending_actions` (`id`, `conversation_id`, `tool`, `args` json, `status` enum [pending, confirmed, rejected, expired, executed], `expires_at`).
- Cuando se emite una `frontend_action` con `confirm`/`manual`, se persiste y al LLM se le devuelve "awaiting_user".
- Endpoint `POST /chatbot/actions/{id}/confirm` con body `{accept: bool}`.
- Si `accept=true`, el widget ejecuta y reporta de vuelta. Si `false`, se rechaza y se notifica al LLM en el siguiente turno.
- Limpieza periódica con `chatbot:cleanup-actions` schedulable.
**DoD:**
- Test E2E de un flujo `confirm`: LLM pide ejecutar, usuario rechaza, en el siguiente turno el LLM lo "sabe".
- Test de expiración.
**Dependencias:** E08, E11.

---

### Fase 3 — Distribución

#### E17 · Página dedicada de chat
**Objetivo:** ofrecer una vista publicable `/chatbot` que comparta estado con el widget.
**Alcance:**
- Vista Blade publicable que monta `<chatbot-widget mode="page">` a pantalla completa.
- Layout configurable (`chatbot.page.layout` apunta a un layout del host).
- Listado lateral de conversaciones con búsqueda y borrado.
- Comparte `conversation_id` con el widget vía `localStorage` con clave canonical.
**DoD:**
- Abrir conversación X en el widget, navegar a `/chatbot`: se ve la misma conversación.
**Dependencias:** E10, E12.

#### E18 · Comando artisan de instalación
**Objetivo:** un solo comando deja el host listo para usar el chatbot.
**Alcance:**
- `php artisan chatbot:install` interactivo:
  1. Publica config, migrations, views, assets, prompts.
  2. Pregunta provider/model, escribe `.env` keys necesarias.
  3. Detecta Spatie y propone resolver.
  4. Genera stub de `ScopeResolver` en `app/Chatbot/`.
  5. Genera stub de tool de ejemplo (`ListMyInvoicesTool`).
  6. Inserta `<script src="{{ url('chatbot/widget.js') }}" defer></script>` y `<chatbot-widget>` en el layout principal (con confirmación).
  7. Da instrucciones finales (rutas excluidas si las hay, cómo registrar tools).
**DoD:**
- En un Laravel 11 limpio, el comando deja un chatbot funcional contra un provider con API key dummy (en modo `Prism::fake`).
**Dependencias:** todas las anteriores.

#### E19 · Distribución y composer privado
**Objetivo:** publicar en el git privado de la empresa y consumirlo desde proyectos host.
**Alcance:**
- Documentar en README la receta:
  ```
  "repositories": [
    { "type": "vcs", "url": "https://github.com/rnkr69/lara-chatbot.git" }
  ],
  "require": {
    "empresa/chatbot": "^1.0"
  }
  ```
- Recomendar Satis/Packeton/Private Packagist si la empresa lo tiene; si no, basta `vcs`.
- Pipeline CI (GitHub Actions o equivalente) con: lint, tests en PHP 8.2/8.3/8.4 × Laravel 11/12, build del bundle JS, atado de release a tag git.
- Tag `v1.0.0` con CHANGELOG inicial.
**DoD:**
- Un proyecto host puede `composer update` y recibir nuevas versiones publicadas con un tag.
**Dependencias:** E18.

---

### Fase 4 — Calidad

#### E20 · Testing
**Objetivo:** suite estable que da confianza en upgrades de Prism y de Laravel.
**Alcance:**
- Pest + Orchestra Testbench.
- Matriz de CI: PHP 8.2/8.3/8.4 × Laravel 11/12.
- Cobertura mínima: contratos críticos (Authorizer, ScopeResolver, ChatService, ToolRegistry).
- Tests de integración con `Prism::fake()` para todas las primitivas FE.
- Tests E2E del widget con Playwright headless.
**DoD:**
- CI verde en toda la matriz.
- Cobertura ≥ 75% en `src/Authorization`, `src/Services`, `src/Tools`.
**Dependencias:** todas.

#### E21 · Documentación host
**Objetivo:** que un dev del host pueda añadir tools propias en < 30 min.
**Alcance:**
- README extenso.
- `docs/`:
  - `getting-started.md`
  - `authorization.md` (con sección Spatie + ownership + manager→equipo paso a paso).
  - `backend-tools.md`
  - `frontend-tools.md`
  - `block-renderers.md`
  - `mcp.md`
  - `deployment.md`
  - `troubleshooting.md`
- Cada doc con un ejemplo end-to-end.
- Diagramas de los flujos de la sección 1 en formato Mermaid.
**DoD:**
- Un dev externo al equipo levanta un chatbot funcional siguiendo `getting-started.md` sin pedir ayuda.
**Dependencias:** todas.

---

## 6. Versionado y release

- SemVer estricto.
- `main` siempre desplegable.
- Ramas de feature por épica: `feat/eXX-slug`.
- Cada release con:
  - Tag `vX.Y.Z`.
  - Entrada en `CHANGELOG.md` (formato Keep a Changelog).
  - Notas de upgrade si hay breaking change.
- Política: el paquete soporta las dos últimas mayors de Laravel. Cuando salga Laravel 13, se mantienen 11 y 12 en una rama LTS hasta que la mayoría de proyectos host migren.

---

## 7. Apéndice — Ejemplo de uso desde un proyecto host

> Estos ejemplos no se implementan en el paquete; son referencia para el README y `examples/`.

### 7.1 Backend tool: listar facturas del usuario respetando scope
- Clase en `app/Chatbot/Tools/ListInvoicesTool.php`.
- `permissions(): ['invoices.read']`.
- `defaultScope(): AccessScope::self`.
- `parameters()`: `{from?: date, to?: date, status?: enum}`.
- `handle()` usa `accessibleQuery(Invoice::query())` y devuelve `ToolResult::ok($rows)`.

### 7.2 Frontend tool registrada en JS
```
window.Chatbot.registerTool('open_invoice_drawer', ({ invoice_id }) => {
  document.querySelector('app-invoice-drawer').open(invoice_id);
});
```
Y el LLM puede ahora llamar `invoke_host_action('open_invoice_drawer', {invoice_id: 42})`.

### 7.3 Page context declarado
En la vista Blade del listado de facturas:
```
<meta name="chatbot:context" content='{"route":"invoices.index","filters":{"status":"unpaid"}}'>
```

### 7.4 Slot de bloque custom
```
<template data-chatbot-block-template="invoice-card">
  <div class="invoice-card">
    <h4 data-slot="title"></h4>
    <p data-slot="amount"></p>
    <button data-slot="action"></button>
  </div>
</template>
```

### 7.5 ScopeResolver del host (manager → equipo)
- Implementa `resolveAccessibleUserIds`:
  - `self` → `[$user->id]`
  - `team` → `User::where('manager_id', $user->id)->pluck('id')->push($user->id)->all()`
  - `all` → `User::pluck('id')->all()` (o `[]` con flag de bypass total).

---

## 8. Decisiones diferidas (a revisar antes de cerrar v1)
- ¿Soporte para multimodal (imágenes/voz) en mensajes? → fuera de v1.
- ¿Backend tools con `confirm`/`manual`? → fuera de v1, requiere flujo de pausa/reanudación complejo.
- ¿Embeddings y RAG sobre datos del host? → módulo opcional v1.1.
- ¿Multiidioma del system prompt? → ya soportado por ser una vista Blade; documentar.
- ¿Telemetría de uso (tokens, costes por usuario)? → tabla `chatbot_usage`, valorar v1.1.

---

**Fin del documento.** Cualquier épica puede expandirse a su propio archivo `docs/epics/eXX.md` cuando se vaya a abordar.
