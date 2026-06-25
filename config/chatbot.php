<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | LLM provider y modelo
    |--------------------------------------------------------------------------
    |
    | Identifica al proveedor que Prism usará por defecto. Ver
    | https://prismphp.com para la lista completa. Cada proveedor lee sus
    | credenciales del config de Prism (services o variables de entorno).
    | El paquete no toca esas credenciales — el host configura Prism aparte.
    |
    | El modelo concreto se puede sobreescribir por conversación (E05).
    |
    */

    'provider' => env('CHATBOT_PROVIDER', 'anthropic'),
    'model'    => env('CHATBOT_MODEL', 'claude-sonnet-4-6'),

    /*
    |--------------------------------------------------------------------------
    | LLM options (v1.1.1)
    |--------------------------------------------------------------------------
    |
    | `cache_system_prompt`: cuando `true` y el provider activo soporta
    | prompt caching (Anthropic Claude 3+), el `LlmGateway` separa el system
    | prompt en bloques cacheables (header, tools enumeration, decision
    | strategy) y dinámicos (page context, pending actions) — y marca los
    | primeros con `cache_control: ephemeral`. Anthropic factura el bloque
    | cacheado al 10% en cache hits y reduce ~50% la latencia. TTL ~5min.
    |
    | Para conversaciones de 10+ turns con un system prompt grande, el ahorro
    | es de ~75% del input cost. Default `true` — los hosts en providers sin
    | cache (Ollama, viejos modelos) lo pueden poner a `false`.
    |
    */
    'llm' => [
        'cache_system_prompt' => filter_var(env('CHATBOT_CACHE_SYSTEM_PROMPT', true), FILTER_VALIDATE_BOOLEAN),

        // `send_blocks_to_model`: si el resultado de una tool que se envía al
        // modelo debe incluir los `blocks`. Por defecto `false`: los blocks son
        // SOLO para el widget (presentación y replay en recarga); enviarlos al
        // LLM hace que los reproduzca como tabla/markdown en su texto, que el
        // widget también renderiza — el usuario ve el contenido DUPLICADO. El
        // LLM razona sobre `data`; los blocks son presentación. Ponlo a `true`
        // solo si quieres que el modelo razone sobre las filas del bloque.
        'send_blocks_to_model' => filter_var(env('CHATBOT_LLM_SEND_BLOCKS_TO_MODEL', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cliente HTTP global (v1.1, findings #1)
    |--------------------------------------------------------------------------
    |
    | Algunos proveedores corporativos exponen los endpoints LLM detrás de un
    | proxy con un CA root no presente en el trust store del sistema (típico
    | tras un proxy LiteLLM corporativo). Prism construye el cliente HTTP internamente y
    | hasta su soporte upstream de `request_options` no expone toggles para
    | desactivar verificación SSL.
    |
    | `verify` controla la verificación SSL del cliente HTTP global de
    | Laravel. `true` (default y único valor seguro en producción) deja la
    | verificación intacta. `false` la desactiva — sólo dev / staging detrás
    | de un proxy con cert self-signed; el ServiceProvider llama
    | `Http::globalOptions(['verify' => false])` y emite un Log::warning para
    | que quede rastro. Si tu host gestiona el cert correctamente (CA bundle
    | propio), prefiere `CURL_CA_BUNDLE` o `CURLOPT_CAINFO` antes que tocar
    | esta config.
    |
    */
    'http' => [
        'verify' => filter_var(env('CHATBOT_HTTP_VERIFY', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | System prompt
    |--------------------------------------------------------------------------
    |
    | El system prompt se construye renderizando una vista Blade. La vista
    | base la provee el paquete y se publica con el tag `chatbot-prompts`.
    |
    | `addendum_view` (opcional) permite al host inyectar instrucciones
    | adicionales sin sobrescribir la base — útil para reglas de dominio,
    | jerga, formatos de fecha, glosario, etc. Gap cross-host de E05.
    |
    */

    'system_prompt' => [
        'view'          => 'chatbot::system_prompt',
        'addendum_view' => env('CHATBOT_SYSTEM_PROMPT_ADDENDUM', null),

        /*
        |----------------------------------------------------------------------
        | Decision strategy (v1.1.1, finding #12.a)
        |----------------------------------------------------------------------
        |
        | `decision_strategy` controla la sección "Page context — decision
        | strategy" que el builder añade tras el page context. Enseña al LLM
        | a aprovechar la página actual (modificar el grid existente vs.
        | pintar la tabla en el chat, usar el form schema antes que un write
        | tool, etc.).
        |
        |   - true (default)        — emite las reglas estándar del package.
        |   - false                 — desactiva la sección.
        |   - 'view::name'          — usa una vista Blade del host como
        |                              fuente (deja que el host customize sin
        |                              perder la sección).
        |
        */
        'decision_strategy' => env('CHATBOT_SYSTEM_PROMPT_DECISION_STRATEGY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rutas HTTP
    |--------------------------------------------------------------------------
    |
    | Todas las rutas del paquete viven bajo este grupo: prefix + middleware
    | + dominio opcional. Las rutas concretas se añaden en E09/E10/E16/E17.
    |
    | `auth` está incluido por defecto porque la autorización por usuario es
    | requisito del paquete (ROADMAP §2). Si tu host usa Sanctum/JWT/...
    | añade el guard correspondiente.
    |
    */

    'route' => [
        'prefix'     => env('CHATBOT_ROUTE_PREFIX', 'chatbot'),
        'middleware' => ['web', 'auth'],
        'domain'     => env('CHATBOT_ROUTE_DOMAIN', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistencia
    |--------------------------------------------------------------------------
    |
    | `connection` deja que el host elija una conexión BD distinta a la
    | default (útil en multi-DB). null = conexión por defecto.
    |
    | `prefix` se aplica a las dos tablas (`{prefix}conversations`,
    | `{prefix}messages`). Cambiar el prefix después de instalar exige
    | renombrar las tablas en el host.
    |
    | `soft_delete` controla si las conversaciones soportan borrado lógico.
    | RESERVADA en v1 — siempre se asume `true` (la migración crea la columna
    | `deleted_at` y el modelo `Conversation` siempre aplica el trait
    | `SoftDeletes`). Hacer este comportamiento opcional sin acoplar al host
    | clases híbridas es complicado y se difiere a v1.1.
    |
    */

    'persistence' => [
        'connection'  => env('CHATBOT_DB_CONNECTION', null),
        'prefix'      => env('CHATBOT_DB_PREFIX', 'chatbot_'),
        'soft_delete' => true, // reservado en v1 — ver nota
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-titling de conversaciones
    |--------------------------------------------------------------------------
    |
    | El primer mensaje del usuario se usa como título de la conversación.
    | `max_length` recorta en el límite de palabra más cercano (con elipsis).
    | `use_llm` está reservado para una iteración futura: cuando esté `true`
    | el paquete delegará el titling a un modelo barato (p.ej. Haiku) en
    | lugar de truncar el mensaje crudo.
    |
    */
    'titles' => [
        'max_length' => env('CHATBOT_TITLE_MAX_LENGTH', 60),
        'use_llm'    => env('CHATBOT_TITLE_USE_LLM', false),
        'llm_model'  => env('CHATBOT_TITLE_LLM_MODEL', null),
        'llm_prompt' => env(
            'CHATBOT_TITLE_LLM_PROMPT',
            'Generate a short 3-5 word title summarizing the following user message. '
            . 'Reply with only the title — no quotes, no punctuation, no explanation.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autorización
    |--------------------------------------------------------------------------
    |
    | El paquete autoriza tools en cascada (ROADMAP §2):
    |   permiso → scope de datos → ownership puntual.
    | A partir de E04 se añade una 4ª dimensión opcional: tenant scope
    | (gap de hosts multi-tenant).
    |
    | `resolver` decide cómo se valida el permiso de la tool:
    |   - 'spatie' (default) — usa Spatie\Permission si está presente; si no,
    |     hace fallback a Gate. Si quieres exigir Spatie, ver E04.
    |   - 'gate'             — siempre Gate::allows().
    |   - 'custom'           — usa la clase declarada en `authorizer`.
    |
    | `scope_resolver` y `tenant_resolver` los implementa el host. null = se
    | usa el resolver nulo (sin restricciones extra). E04 detalla el contrato.
    |
    | `default_scope` es el scope que asume una tool si no declara otro.
    |
    */

    'authorization' => [
        'resolver'        => env('CHATBOT_AUTH_RESOLVER', 'spatie'),
        'authorizer'      => null,
        'scope_resolver'  => null,
        'tenant_resolver' => null,
        'default_scope'   => 'self',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools (backend)
    |--------------------------------------------------------------------------
    |
    | `auto_discover` activa el escaneo de las paths declaradas para
    | localizar implementaciones de Rnkr69\LaraChatbot\Tools\Contracts\BackendTool.
    | El registro detallado vive en E06 (ToolRegistry).
    |
    | `paths` lista directorios donde el host coloca sus tools. Los
    | absolutos se respetan tal cual; los relativos se resuelven contra
    | base_path() en el ServiceProvider.
    |
    */

    'tools' => [
        'auto_discover' => true,
        'paths' => [
            'app/Chatbot/Tools',
        ],

        /*
        |----------------------------------------------------------------------
        | Frontend primitives (E11)
        |----------------------------------------------------------------------
        |
        | El paquete entrega 8 primitivas FE listas para registrar — los hosts
        | no las quieren todas en cualquier app. Cada una se puede activar
        | desactivar individualmente. Comentar/borrar líneas reduce el catálogo
        | que el LLM ve. El orden no importa.
        |
        | `DownloadFileTool` requiere además `download_file.allowed_disks` (más
        | abajo); sin discos habilitados la tool falla con un error explicativo.
        |
        | v1.1.2: `HighlightTool` se elimina del catálogo (finding #15).
        | Casos cubiertos antes por `highlight` se resuelven mejor con
        | `navigate`, `render_block` o `fill_form` + `invoke_host_action`.
        |
        */
        'frontend_primitives' => [
            \Rnkr69\LaraChatbot\Tools\Frontend\NavigateTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\ToggleVisibilityTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\FillFormTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\ShowToastTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\OpenModalTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\RenderBlockTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\InvokeHostActionTool::class,
            \Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Backend primitives (v2.2)
        |----------------------------------------------------------------------
        |
        | Tools backend que envía el paquete para cubrir el ciclo
        | conversacional del Personal Dashboard: el LLM crea, edita y borra
        | widgets/dashboards desde el chat sin tener que click-modar el panel.
        |
        | Cada tool es desactivable individualmente por el host con la clave
        | `chatbot.tools.{name}.enabled` (más abajo). Quitar una línea aquí
        | tiene el mismo efecto pero edita la lista publicada en vez del flag
        | per-tool.
        |
        | Las primitives viven en `src/Tools/Backend/` del paquete y NO están
        | cubiertas por `chatbot.tools.paths` (que apunta al host) — por eso
        | se registran explícitamente, mismo patrón que `frontend_primitives`.
        |
        */
        'backend_primitives' => [
            \Rnkr69\LaraChatbot\Tools\Backend\AddToDashboardTool::class,
            \Rnkr69\LaraChatbot\Tools\Backend\EditWidgetTool::class,
            \Rnkr69\LaraChatbot\Tools\Backend\DeleteWidgetTool::class,
            \Rnkr69\LaraChatbot\Tools\Backend\EditDashboardTool::class,
            \Rnkr69\LaraChatbot\Tools\Backend\DeleteDashboardTool::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Per-tool toggles (v2.2)
        |----------------------------------------------------------------------
        |
        | Cada backend primitive expone `enabled` (default true). Cuando un
        | host quiere desactivar una específica sin tocar `backend_primitives`
        | (p.ej. "permito add_to_dashboard pero NO delete_dashboard porque mi
        | user base es demasiado heterogénea para borrar por chat"), pone:
        |
        |   'delete_dashboard' => ['enabled' => false],
        |
        | El `ChatbotServiceProvider::registerBackendPrimitives()` consulta el
        | flag antes de cada `register()`. El `SystemPromptBuilder` también
        | omite las hint lines de tools desactivadas para que el LLM no las
        | sugiera al usuario.
        |
        */
        'add_to_dashboard' => [
            'enabled' => true,
        ],
        'edit_widget' => [
            'enabled' => true,
        ],
        'delete_widget' => [
            'enabled' => true,
        ],
        'edit_dashboard' => [
            'enabled' => true,
        ],
        'delete_dashboard' => [
            'enabled' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | DownloadFileTool — descarga de documentos/adjuntos server-side
        |----------------------------------------------------------------------
        |
        | Modelo fail-secure por default:
        |   - `allowed_disks` lista los discos del host que la tool puede
        |     firmar. Vacío = ninguno (la tool devolverá error explicativo).
        |     Añadir aquí los discos que el host quiere exponer:
        |     ['s3-invoices', 'r2-attachments', ...].
        |   - `max_expires_in` cap de TTL de la URL firmada en segundos.
        |     El LLM puede pedir cualquier valor entre 30s y este máximo.
        |
        | Para validar ownership por dominio (ej. "¿este PDF pertenece al
        | usuario?"), el host debe subclase `DownloadFileTool` y override
        | `assertCanDownload()` — la versión base no tiene contexto para
        | aplicar reglas de negocio.
        |
        */
        'download_file' => [
            'allowed_disks'   => [],
            'max_expires_in'  => 3600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP — servidores externos
    |--------------------------------------------------------------------------
    |
    | El bridge MCP (E07) usa prism-php/relay para exponer tools de servidores
    | MCP externos como `BackendTool` con prefijo `mcp.<server>.<tool>`. Si
    | `prism-php/relay` no está instalado en el host, esta sección se ignora
    | silenciosamente y el comando `chatbot:tools:list` lo señala con un
    | warning accionable.
    |
    | El transporte (HTTP/STDIO), URL o comando, headers, timeouts, etc. se
    | configuran en `config/relay.php` (publicable con
    | `php artisan vendor:publish --tag=relay-config`). Aquí sólo se mapea
    | metadata adicional que el bridge necesita: si exponer el server al
    | LLM (`enabled`), qué permisos exigir al usuario para invocar sus tools
    | (`permissions`) y cuántos segundos cachear la lista de tools
    | (`cache_ttl`). Las claves del array DEBEN coincidir con los nombres de
    | server declarados en `config/relay.php`.
    |
    | Ejemplo:
    |   'servers' => [
    |       'tickets' => [
    |           'enabled'     => true,
    |           'permissions' => ['tickets.use_mcp'],
    |           'cache_ttl'   => 300,
    |       ],
    |   ],
    |
    */

    'mcp' => [
        'servers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget Web Component
    |--------------------------------------------------------------------------
    |
    | Configuración por defecto del <chatbot-widget>. Cualquier instancia
    | puede sobrescribir vía atributos HTML (ROADMAP §3.5/E12).
    |
    */

    'widget' => [
        'enabled'      => true,
        'theme'        => env('CHATBOT_WIDGET_THEME', 'auto'), // 'light' | 'dark' | 'auto'
        'position'     => env('CHATBOT_WIDGET_POSITION', 'bottom-right'),
        'default_open' => false,
        'asset_path'   => 'vendor/chatbot/chatbot-widget.js',

        /*
        |----------------------------------------------------------------------
        | Suggested prompts (v1.1.1, finding #14.d)
        |----------------------------------------------------------------------
        |
        | Botones que aparecen en el empty state del widget para reducir el
        | "no sé qué pedirle" inicial. Cada item: `['label' => string,
        | 'prompt' => string]`. El label se muestra al usuario; al hacer
        | click, el `prompt` se envía como mensaje del usuario.
        |
        | Soporta dos formas:
        |
        |   - Array estático: lista fija para todos los usuarios.
        |   - Closure `function (Authenticatable $user): array`: lista
        |     dinámica por rol/contexto (resolución server-side al renderizar
        |     el endpoint /chatbot/conversations/empty-state).
        |
        | Cuando esté vacío (`[]`), el widget no muestra los botones — sólo
        | el cursor / placeholder.
        |
        */
        'suggested_prompts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Página dedicada de chat (E17)
    |--------------------------------------------------------------------------
    |
    | Vista publicable bajo `GET /{prefix}` (default `/chatbot`) que monta
    | `<chatbot-widget mode="page">` a pantalla completa con sidebar de
    | conversaciones. Comparte `conversation_id` con el widget flotante vía
    | la clave localStorage `chatbot:active-conversation:v1` (D16).
    |
    |   - `enabled`  desactiva el registro de la ruta. Hosts que sólo quieran
    |                el widget flotante (no la página) lo ponen a false.
    |   - `layout`   nombre de un layout Blade del host (`'layouts.app'`,
    |                `'admin.layout'`, etc.). Si está seteado y la vista
    |                existe, la página se renderiza con `@extends($layout)`
    |                y `@section($section)`. Si es null o la vista no existe,
    |                renderiza standalone (HTML completo desde el paquete).
    |   - `section`  sección del layout en la que inyectar el contenido
    |                cuando hay layout. Default `content`.
    |   - `back_url` (v2.1.1, #26) URL a la que vuelve el enlace "← volver a
    |                la app" que la vista STANDALONE pinta arriba. null = sin
    |                enlace. Sólo aplica en modo standalone (en modo `layout`
    |                la navegación la da el chrome del host). Sin esto, la
    |                página standalone es una isla sin salida.
    |
    | Para personalizar el HTML, publica con `--tag=chatbot-views` y edita
    | `resources/views/vendor/chatbot/page.blade.php`.
    |
    */

    'page' => [
        'enabled'  => true,
        'layout'   => env('CHATBOT_PAGE_LAYOUT', null),
        'section'  => env('CHATBOT_PAGE_SECTION', 'content'),
        'back_url' => env('CHATBOT_PAGE_BACK_URL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Límites
    |--------------------------------------------------------------------------
    |
    | `max_steps`                — corta el loop tool→LLM→tool→… (Prism `withMaxSteps`).
    | `max_tokens`               — tope por respuesta del LLM.
    | `history_messages`         — máximo de mensajes históricos enviados al LLM en
    |                              cada turno (E08). Recorta los más antiguos; el
    |                              turno actual nunca se incluye en este límite.
    | `page_context_kb`          — máximo del JSON de page context aceptado por el
    |                              endpoint de stream (E14, sanitización backend).
    | `rate_limit`               — throttle por usuario sobre /chatbot/stream (E09).
    | `conversations_per_page`   — paginación del listado de conversaciones (E10).
    |                              `default` = ?per_page= por defecto;
    |                              `max`     = tope que el cliente puede pedir.
    | `messages_per_page`        — cursor pagination de mensajes en `show` (E10).
    |                              Mismo shape `default`/`max` que el anterior.
    | `pending_action_ttl`       — TTLs por defecto para los pending actions de
    |                              frontend tools `confirm`/`manual` (E16). El
    |                              `confirm` espera una decisión rápida; el
    |                              `manual` cubre acciones del mundo real
    |                              (firmar, llamar a alguien, etc.) que tardan
    |                              más. El comando `chatbot:cleanup-actions`
    |                              marca como `expired` los `pending` cuyo
    |                              `expires_at < now()`.
    | `pending_actions_in_prompt`— máximo de filas que la sección
    |                              `## Pending actions` del system prompt
    |                              vuelca al LLM en el siguiente turno.
    |                              Se priorizan los más recientes.
    |
    */

    'limits' => [
        'max_steps'        => 5,
        'max_tokens'       => 4096,
        'history_messages' => 20,
        'page_context_kb'  => 16,
        'rate_limit' => [
            'enabled'             => true,
            'requests_per_minute' => 30,
        ],
        'conversations_per_page' => [
            'default' => 20,
            'max'     => 100,
        ],
        'messages_per_page' => [
            'default' => 50,
            'max'     => 200,
        ],
        'pending_action_ttl' => [
            'confirm' => 600,    // 10 min — aprobar/rechazar UI.
            'manual'  => 86_400, // 24 h  — acción humana real.
            // v1.1.3 #16: TTL corto para auto-confirmed rows; sólo importa
            // mientras el widget pueda hacer POST-back tras ejecutar la
            // primitive. Tras este TTL, los pending action no entregados
            // se barren con `chatbot:cleanup-actions` igual que los demás.
            'auto'    => 60,     // 1 min — ventana para POST-back de fallos.
        ],
        'pending_actions_in_prompt' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backpack integration
    |--------------------------------------------------------------------------
    |
    | Knobs específicos del provider opcional `BackpackPageContextProvider`.
    |
    | `fk_options_cap` — número máximo de FK options que el provider
    |                    enumera server-side por cada select de un form
    |                    create/edit (#9.g). Si la tabla referenciada tiene
    |                    más filas, el provider emite `options_truncated:
    |                    true` y deja la resolución label→id al LLM vía un
    |                    read tool (`list_*`). Subir si tus FKs son pequeños
    |                    (~ < 500 rows típicos); bajar si la enumeración
    |                    pesa demasiado al renderizar `/admin/<entity>/create`.
    |
    | `datatables_row_decoration` (v1.1.3, finding #20) — activa el hook
    |                    `draw.dt` integrado en el bundle: por cada fila del
    |                    `#crudTable` el widget extrae el id del enlace
    |                    `<a href=".../{id}/show">` (o `/edit`) y setea
    |                    `data-chatbot-row-id="<id>"`. Así el LLM puede
    |                    referir filas con primitives `toggle_visibility`
    |                    sin que cada host wira su propio hook. Default
    |                    `true` — los hosts que no usen Backpack DataTables
    |                    o que ya tengan su propio hook lo pueden deshabilitar
    |                    con `CHATBOT_BACKPACK_DT_DECORATION=false`.
    |
    | `datatables_selected_sync` (v1.1.4, finding #26) — propaga el estado
    |                    de los checkboxes de bulk-action de Backpack al
    |                    page context del chatbot (`crud.selected_ids`) en
    |                    tiempo real. Sin esto el `BackpackPageContextProvider`
    |                    emite la selección sólo una vez (en el primer render
    |                    server-side) y cualquier click del usuario en la
    |                    grid deja el contexto desincronizado. Independiente
    |                    de `datatables_row_decoration`: cada flag controla
    |                    una capa distinta del bundle. Default `true` — un
    |                    host puede deshabilitarlo con
    |                    `CHATBOT_BACKPACK_DT_SELECTED_SYNC=false`.
    |
    | `use_bootstrap` (v2.1, #19) — estrategia de theming del Personal
    |                    Dashboard. Cuando el dashboard se renderiza en modo
    |                    `layout` (hereda el `<head>` del host) y el host
    |                    carga Bootstrap (Backpack 6 = Bootstrap 5), los
    |                    block renderers (`table`/`card`/`list`) usan las
    |                    clases de Bootstrap del host en vez del CSS propio
    |                    del paquete — consistencia con el panel, sin "isla"
    |                    visual. Valores:
    |                      - `'auto'` (default): true si el dashboard está en
    |                        modo `layout` Y el paquete Backpack está
    |                        instalado; false en cualquier otro caso (modo
    |                        standalone NO tiene `<head>` del host → nunca
    |                        hay Bootstrap disponible).
    |                      - `true` / `false`: fuerza el modo. Útil para hosts
    |                        con un layout propio Bootstrap-based no-Backpack,
    |                        o para hosts Backpack con un theme custom que
    |                        prefieren el CSS propio del paquete.
    |                    El widget flotante NO se ve afectado: vive en shadow
    |                    DOM, el Bootstrap del host no penetra — mantiene
    |                    siempre su CSS propio encapsulado.
    */

    'backpack' => [
        'fk_options_cap'             => 200,
        'datatables_row_decoration'  => filter_var(env('CHATBOT_BACKPACK_DT_DECORATION', true), FILTER_VALIDATE_BOOLEAN),
        'datatables_selected_sync'   => filter_var(env('CHATBOT_BACKPACK_DT_SELECTED_SYNC', true), FILTER_VALIDATE_BOOLEAN),
        'use_bootstrap'              => env('CHATBOT_BACKPACK_USE_BOOTSTRAP', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal Dashboard (v2.0)
    |--------------------------------------------------------------------------
    |
    | El dashboard personal permite al usuario fijar (📌) blocks producidos
    | por el chat — tablas, KPIs, charts — y volver a ellos en una ruta
    | dedicada `/chatbot/dashboard`. Al abrir el dashboard, cada widget
    | re-ejecuta su tool de origen respetando la misma cascada de
    | autorización del chat (`permission → scope → tenant → ownership`).
    |
    | Esta sección define los topes y políticas que el motor consume. Cuando
    | `enabled = false`, el paquete no registra ni la ruta `/chatbot/dashboard`
    | ni los endpoints API; los tools siguen funcionando idéntico, sólo
    | desaparece la capacidad de pinear.
    |
    */

    'dashboard' => [
        'enabled' => filter_var(env('CHATBOT_DASHBOARD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        /*
        |----------------------------------------------------------------------
        | Página HTML del dashboard (E4 — mirror de chatbot.page.{layout,section})
        |----------------------------------------------------------------------
        |
        | Misma mecánica que `chatbot.page.layout` (E17, D16): si `layout` es
        | string Y `View::exists($layout)`, la vista `chatbot::dashboard_layout`
        | hace `@extends($layout)` + `@section($section)`. Si es null o la
        | vista no existe (log warning), cae a `chatbot::dashboard` standalone.
        |
        | v2.1.1 (#26):
        |   - `mount_widget` — en modo `layout`, monta el `<chatbot-widget>`
        |     flotante en la propia página del dashboard para que el usuario
        |     pueda pinear DESDE el dashboard. Sin esto la página cuyo
        |     propósito es coleccionar bloques pineados es la única donde no
        |     puedes generarlos. Default `true`. SÓLO aplica en modo `layout`:
        |     la vista STANDALONE (`dashboard.blade.php`) no monta el widget
        |     en ningún caso — es una página HTML propia del paquete sin el
        |     chrome del host (v2.1.2, #30: el comentario decía "ambos modos",
        |     era incorrecto). Ponlo a `false` si el host inyecta el widget
        |     por su cuenta (ver `extras_view` abajo).
        |   - `back_url` — URL del enlace "← volver a la app" que la vista
        |     STANDALONE pinta arriba. null = sin enlace. En modo `layout` la
        |     navegación la da el chrome del host, así que ahí se ignora.
        |
        | v2.1.3 (#34):
        |   - `extras_view` — nombre (string Blade) de una vista del host
        |     que `dashboard_layout.blade.php` incluye DENTRO de la sección,
        |     justo después del root del dashboard. Sustituye al stack
        |     `chatbot_dashboard_extras` que v2.1.2 (#31) intentó documentar
        |     y que en la práctica era inalcanzable: vivía dentro del
        |     `@section…@endsection` ya capturado, así que un `@push` desde
        |     el `$layout` view (la usage que documentábamos) nunca llegaba
        |     a renderizarse. El nuevo mecanismo es un `@include` síncrono:
        |     la vista del host se evalúa en el contexto del padre, así
        |     que un `@push('after_scripts')` dentro de ella SÍ aterriza
        |     donde toca. Default `null` (sin extras). El controller
        |     valida `View::exists($extras_view)` y degrada a null con
        |     log warning si no existe — misma política que `layout`.
        |
        | Caso de uso típico: `extras_view = 'admin._chatbot_widget'`,
        | con `mount_widget = false`, donde la vista del host monta su
        | `<chatbot-widget>` + carga `chatbot-actions.js` (frontend tools,
        | renderer del host, page context) y se beneficia del shim
        | upgrade del bundle (v2.1.3 #35) sin tener que hackear el orden
        | de carga.
        |
        */
        'layout'       => env('CHATBOT_DASHBOARD_LAYOUT', null),
        'section'      => env('CHATBOT_DASHBOARD_SECTION', 'content'),
        'mount_widget' => filter_var(env('CHATBOT_DASHBOARD_MOUNT_WIDGET', true), FILTER_VALIDATE_BOOLEAN),
        'back_url'     => env('CHATBOT_DASHBOARD_BACK_URL', null),
        'extras_view'  => env('CHATBOT_DASHBOARD_EXTRAS_VIEW', null),

        /*
        |----------------------------------------------------------------------
        | Bundle JS del dashboard (E5)
        |----------------------------------------------------------------------
        |
        | Path relativo a `public/` del bundle separado que monta la grilla
        | gridstack + Chart.js + DashboardApp. E4 lo emite como `data-asset`
        | en la vista; E5 produce el bundle real. Análogo a
        | `chatbot.widget.asset_path` para el widget flotante.
        |
        */
        'asset_path' => 'vendor/chatbot/chatbot-dashboard.js',

        /*
        |----------------------------------------------------------------------
        | Topes por usuario
        |----------------------------------------------------------------------
        |
        | `max_dashboards_per_user` cap el número de dashboards nombrados que
        | un usuario puede tener (soft-deleted no cuentan). 20 cubre con
        | holgura los casos típicos (`Mi panel`, áreas funcionales, fotos
        | históricas).
        |
        | `max_widgets_per_dashboard` cap el número de pins por dashboard.
        | 50 es ya un límite operativo: por encima el grid se vuelve
        | inmanejable y el replay bulk al abrir penaliza el TTFB.
        |
        */
        'max_dashboards_per_user'   => 20,
        'max_widgets_per_dashboard' => 50,

        /*
        |----------------------------------------------------------------------
        | Cap de tamaño del snapshot persistido
        |----------------------------------------------------------------------
        |
        | El JSON `snapshot.data` se guarda íntegro en la tabla
        | `chatbot_dashboard_widgets`. Para tablas con 10k filas o blobs
        | embebidos el tamaño se dispara. Si excede este cap, el controller
        | E4 sólo persiste `data.head` (primeras N filas) y un marker
        | `truncated: true` — el replay lo reemplazará por datos frescos al
        | abrir.
        |
        | Default 256 KB. Subir si los dashboards del host muestran
        | datasets densos pre-computados.
        |
        */
        'snapshot_max_bytes' => 256 * 1024,

        /*
        |----------------------------------------------------------------------
        | Replay engine (E3 — keys-only en E2)
        |----------------------------------------------------------------------
        |
        | Estas claves las consume `ReplayService` (E3):
        |   - `driver`: driver de `Illuminate\Support\Facades\Concurrency` con
        |     el que el bulk-replay ejecuta los tasks. El default es `sync`
        |     (secuencial, en el mismo proceso) — NO el `concurrency.default`
        |     del host, que en Laravel 11+ es `process` y revienta en
        |     Windows/WAMP, shared hosting sin `pcntl` y contenedores sin
        |     `proc_open`. `sync` es viable en cualquier entorno; un host con
        |     infra adecuada sube a `process`/`fork` aquí (o pone
        |     `CHATBOT_REPLAY_DRIVER`). v2.1.1 (#20).
        |   - `concurrency`: máximo de tools que el bulk-replay ejecuta en
        |     paralelo al abrir el dashboard. Cap conservador (8) para no
        |     ahogar el servidor cuando un usuario tiene 30+ widgets. Con el
        |     driver `sync` el cap no aporta paralelismo, sólo chunkea.
        |   - `timeout_seconds`: corte por tool. Si un tool tarda más, el
        |     replay marca el widget como `error` y conserva el snapshot.
        |   - `rate_limit_per_user_per_minute`: throttle por usuario sobre
        |     los endpoints de refresh manual + bulk. 60/min es suficiente
        |     para abrir el dashboard varias veces sin pegar el rate-limit.
        |
        */
        'replay' => [
            'driver'                         => env('CHATBOT_REPLAY_DRIVER', 'sync'),
            'concurrency'                    => 8,
            'timeout_seconds'                => 15,
            'rate_limit_per_user_per_minute' => 60,
        ],

        /*
        |----------------------------------------------------------------------
        | Housekeeping (E10) — `chatbot:dashboards:prune`
        |----------------------------------------------------------------------
        |
        | Thresholds default que consume el comando `chatbot:dashboards:prune`.
        | Cada flag (`--source-missing`, `--stale`, `--empty-dashboards`,
        | `--purge-soft-deleted`) lee la key correspondiente; el host puede
        | override puntualmente vía `--source-missing-days=N`, etc.
        |
        |   - `source_missing_days`: días que un widget debe llevar marcado
        |     `source_missing` antes de ser candidato (tool desaparecida del
        |     registry sostenidamente). 30 = 1 mes de gracia.
        |   - `stale_days`: días sin un refresh reciente (cualquier status que
        |     no sea `source_missing`) antes de ser candidato. 90 = 1 trimestre.
        |   - `empty_dashboard_days`: días desde la creación de un dashboard
        |     sin widgets activos antes de ser candidato. 180 = 1 semestre.
        |   - `purge_soft_deleted_days`: días desde el soft-delete antes de
        |     ejecutar el `forceDelete()` definitivo (hard delete). 30 =
        |     ventana de recovery razonable.
        |
        | Receta de scheduler — añadir a `app/Console/Kernel.php` del host:
        |
        |     $schedule->command('chatbot:dashboards:prune', [
        |         '--source-missing', '--stale', '--empty-dashboards', '--force',
        |     ])->weekly();
        |
        */
        'prune' => [
            'source_missing_days'     => 30,
            'stale_days'              => 90,
            'empty_dashboard_days'    => 180,
            'purge_soft_deleted_days' => 30,
        ],

        /*
        |----------------------------------------------------------------------
        | Renderer del block `chart` en el bundle del dashboard
        |----------------------------------------------------------------------
        |
        | `'chartjs'` (default) → el bundle separado del dashboard incluye
        | Chart.js (~42 KB gzip) como renderer built-in. Los hosts pueden
        | seguir overrideando vía `window.Chatbot.registerBlockRenderer('chart', …)`
        | — la API se mantiene. `'none'` → ningún renderer built-in para
        | charts; el host DEBE registrar el suyo o los widgets `chart`
        | mostrarán el fallback "renderer not registered".
        |
        */
        'chart_renderer' => 'chartjs',

        /*
        |----------------------------------------------------------------------
        | Política de refresh por defecto al pinear un block
        |----------------------------------------------------------------------
        |
        | Valor inicial de `refresh_policy` cuando el usuario hace pin sin
        | elegir explícitamente. Permitidos: `'on_open'`, `'manual'`,
        | `'never'`. El modelo `DashboardWidget` lo casta al enum
        | `WidgetRefreshPolicy`.
        |
        */
        'default_refresh_policy' => 'on_open',
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetría de coste (chatbot:cost-report)
    |--------------------------------------------------------------------------
    |
    | Tarifas para que `php artisan chatbot:cost-report` convierta los tokens
    | persistidos en `chatbot_messages.tokens_in/out` en coste estimado en
    | USD. Estructura: `prices.{provider}.{model}.{input|output}` — precio en
    | USD por 1M tokens.
    |
    | Defaults indicativos (verifica contra la página de pricing actual de tu
    | proveedor antes de presentar un report — los precios cambian sin aviso).
    | Si un par (provider, model) NO está aquí, el report devuelve `n/a` en
    | la columna de coste (los tokens se reportan igual).
    |
    | Los listeners del evento `Rnkr69\LaraChatbot\Events\MessagePersisted` pueden
    | leer estas mismas tarifas para sinks externos (Prometheus, BigQuery,
    | OpenTelemetry, …). Ver `docs/telemetry.md`.
    |
    */
    'telemetry' => [
        'prices' => [
            'anthropic' => [
                // Precios USD/1M tokens. Verificar contra anthropic.com/pricing.
                'claude-opus-4-7'   => ['input' => 15.00, 'output' => 75.00],
                'claude-sonnet-4-6' => ['input' =>  3.00, 'output' => 15.00],
                'claude-haiku-4-5'  => ['input' =>  1.00, 'output' =>  5.00],
            ],
            'openai' => [
                'gpt-4o'      => ['input' => 2.50, 'output' => 10.00],
                'gpt-4o-mini' => ['input' => 0.15, 'output' =>  0.60],
            ],
            // Otros providers / modelos: el host añade entradas según los
            // que use. Ollama y modelos self-hosted pueden quedarse sin
            // tarifa (la columna de coste sale como `n/a`).
        ],
    ],

];
