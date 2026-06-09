<?php

declare(strict_types=1);

return [
    // Las claves reales (etiquetas del widget, mensajes de error, prompts de
    // confirmación, etc.) se incorporan a partir de E12/E16. Este archivo
    // existe para que el tag `chatbot-lang` tenga algo que publicar y para
    // documentar la estructura esperada por el host.

    // E17 — página dedicada de chat.
    'page_title' => 'Chat',

    // v2.0 / E4 — página dedicada del Personal Dashboard.
    'dashboard_title' => 'Panel',

    // v2.1.1 (#26) — enlace "volver a la app" de las vistas STANDALONE del
    // chat y del dashboard, cuando `chatbot.{page,dashboard}.back_url` está
    // seteado. Sin él, la página standalone es una isla sin salida.
    'back_to_app' => 'Volver a la app',

    // v2.0 / E5 — UI del bundle del dashboard (sidebar, card, header).
    'dashboard' => [
        // v2.1 (#2) — etiqueta para un link de navegación del host hacia
        // /chatbot/dashboard. Distinta del `dashboard_title` de nivel superior
        // (el <title> HTML): un host que ya tiene un item "Dashboard" en su nav
        // de admin usa esta etiqueta más específica para evitar la colisión.
        'menu_label' => 'Mi panel fijado',
        'sidebar' => [
            'new_cta'         => 'Nuevo panel',
            'new_placeholder' => 'Nombre…',
            'create'          => 'Crear',
            'rename'          => 'Renombrar',
            'delete'          => 'Eliminar',
            'set_default'     => 'Marcar por defecto',
            'default_badge'   => 'por defecto',
            'empty_title'     => 'Aún no tienes paneles',
            'empty_hint'      => 'Crea uno para empezar a fijar bloques.',
            'error'           => 'Acción fallida',
            'confirm_delete'  => '¿Eliminar este panel? Se quitarán todos sus widgets.',
        ],
        'card' => [
            'refresh'            => 'Actualizar',
            'remove'             => 'Quitar',
            'view_source'        => 'Ver origen',
            'unauthorized'       => 'No autorizado',
            'error'              => 'Error',
            'stale'              => 'Desfasado',
            'source_missing'     => 'Origen no disponible',
            'no_title'           => 'Widget sin título',
            'refreshing'         => 'Actualizando…',
            'just_now'           => 'hace un instante',
            'inert_actions_hint' => 'Abre el chat para ejecutar acciones.',
        ],
        'header' => [
            'refresh_all'     => 'Actualizar todo',
            'empty_main'      => 'No hay panel seleccionado',
            'empty_main_hint' => 'Crea uno desde la barra lateral para empezar a fijar bloques.',
        ],
        // v2.0 / E6 — pin desde el chat: botón en hover + modal.
        'pin' => [
            'cta'                    => 'Fijar al panel',
            'tooltip'                => 'Fijar este bloque a un panel',
            'modal_title'            => 'Fijar al panel',
            'modal_select_label'     => 'Panel',
            'modal_create_inline'    => 'Crear nuevo panel…',
            'modal_create_name'      => 'Nombre del panel',
            'modal_title_label'      => 'Título',
            'modal_title_placeholder'=> 'Título opcional…',
            'submit'                 => 'Fijar',
            'cancel'                 => 'Cancelar',
            'toast_added'            => 'Añadido a :dashboard',
            'toast_view'             => 'Ver panel',
            'error_dashboard_full'   => 'Este panel está lleno. Elige otro o quita un widget primero.',
            'error_tool_unpinnable'  => 'Este bloque no se puede fijar (su tool no es pinnable).',
            'error_tool_missing'     => 'La tool que originó este bloque ya no está registrada.',
            'error_generic'          => 'No se pudo fijar al panel.',
        ],
        // v2.0 / E7 — renderer por defecto del block chart (Chart.js en el bundle del dashboard).
        'chart' => [
            'invalid_data'  => 'Los datos del gráfico no son válidos o están incompletos.',
            'empty_dataset' => 'No hay puntos de datos para mostrar.',
        ],
        // v2.0 / E8 — block kpi (renderer built-in, presente en widget y dashboard).
        'kpi' => [
            'no_value' => '—',
        ],
    ],

    // v2.2 — tools backend conversacionales (PR-A, PR-B). Los mensajes los
    // emite cada tool en `ToolResult::error(...)`; el LLM los repite verbatim
    // al usuario (cuando una
    // tool da una explicación clara, no parafrasees).
    'add_to_dashboard' => [
        'errors' => [
            'tool_not_found'        => "No conozco una herramienta llamada ':tool'. Las disponibles para tu rol son: :list.",
            'not_pinnable'          => "La acción ':tool' no produce información que se pueda fijar al dashboard.",
            'unauthorized'          => "No tienes permiso para acceder a esa información.",
            'out_of_scope'          => "Esa información está fuera de tu ámbito de acceso.",
            'dashboard_not_found'   => "No tienes un dashboard llamado ':slug'. Tus dashboards son: :list.",
            'no_dashboard'          => "No tienes ningún dashboard todavía. Créalo en /chatbot/dashboard y vuelve a pedírmelo.",
            'cap_reached'           => "Tu dashboard ':name' está lleno (:current/:max). Borra algún widget o usa otro dashboard.",
            'source_args_invalid'   => "No pude usar la herramienta ':tool' porque me faltan datos: :detail. ¿Puedes precisar?",
            'source_runtime'        => "La herramienta ':tool' falló al obtener los datos: :detail.",
            'no_block'              => "La herramienta ':tool' no devolvió contenido pineable esta vez.",
            'ordinal_out_of_range'  => "La herramienta ':tool' sólo devolvió :count bloques ':type'; no hay un :ordinal-ésimo.",
        ],
        'success' => [
            'card_title'       => '✅ Añadido al dashboard',
            'card_description' => 'He añadido **:title** a tu dashboard **:dashboard**. Puedes verlo en :url.',
        ],
    ],
    'edit_widget' => [
        'errors' => [
            'widget_not_found'   => "No tienes un widget con ese identificador. Si me dices su título te lo encuentro.",
            'validation'         => ":detail",
            'nothing_to_change'  => "No me has dicho qué quieres cambiar.",
        ],
        'success' => [
            'card_title'       => '✏️ Widget actualizado',
            'card_description' => 'He aplicado los cambios al widget **:title**: :summary.',
        ],
    ],
    'delete_widget' => [
        'errors' => [
            'widget_not_found' => "No tienes un widget con ese identificador.",
        ],
        'success' => [
            'card_title'       => '🗑️ Widget eliminado',
            'card_description' => 'He quitado el widget **:title** del dashboard.',
        ],
    ],
    'edit_dashboard' => [
        'errors' => [
            'dashboard_not_found' => "No tienes un dashboard con slug ':slug'.",
            'validation'          => ":detail",
            'nothing_to_change'   => "No me has dicho qué quieres cambiar.",
        ],
        'success' => [
            'card_title'       => '✏️ Dashboard actualizado',
            'card_description' => 'He aplicado los cambios al dashboard **:name**: :summary.',
        ],
    ],
    'delete_dashboard' => [
        'errors' => [
            'dashboard_not_found'           => "No tienes un dashboard con slug ':slug'.",
            'would_create_orphan_default'   => "Es tu único dashboard. Crea otro antes o cambia tu petición.",
        ],
        'success' => [
            'card_title'                => '🗑️ Dashboard eliminado',
            'card_description'          => 'He eliminado el dashboard **:name**.',
            'card_description_promoted' => 'He eliminado el dashboard **:name**. Tu nuevo dashboard por defecto es **:promoted**.',
        ],
    ],

    // v1.1 — titling automático y UI de gestión de conversaciones.
    'untitled_conversation'       => 'Conversación sin título',
    'new_conversation'            => 'Nueva conversación',
    'new_conversation_aria'       => 'Empezar una nueva conversación',
    'loading_conversation'        => 'Cargando conversación…',
    'failed_to_load_conversation' => 'No se pudo cargar la conversación',
    // v2.1 (#3) — se muestra en el mensaje del asistente + banner cuando el
    // stream emite un frame `event: error` (LLM caído, red, 5xx).
    'stream_error'                => 'Algo salió mal. Inténtalo de nuevo.',
    'title'                       => 'Chatbot',
    'open_full_page'              => 'Abrir página completa del chat',
];
