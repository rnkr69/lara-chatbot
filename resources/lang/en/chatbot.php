<?php

declare(strict_types=1);

return [
    // The real keys (widget labels, error messages, confirmation prompts,
    // etc.) are added from E12/E16 onwards. This file exists so the
    // `chatbot-lang` tag has something to publish and to document the
    // structure the host is expected to provide.

    // E17 — dedicated chat page.
    'page_title' => 'Chat',

    // v2.0 / E4 — dedicated Personal Dashboard page.
    'dashboard_title' => 'Dashboard',

    // v2.1.1 (#26) — "back to app" link in the STANDALONE chat and dashboard
    // views, when `chatbot.{page,dashboard}.back_url` is set. Without it, the
    // standalone page is a dead-end island.
    'back_to_app' => 'Back to app',

    // v2.0 / E5 — dashboard bundle UI (sidebar, card, header).
    'dashboard' => [
        // v2.1 (#2) — label for a host navigation link to /chatbot/dashboard.
        // Distinct from the top-level `dashboard_title` (the HTML <title>): a
        // host that already has a "Dashboard" item in its admin nav uses this
        // more specific label to avoid a visual collision.
        'menu_label' => 'My pinned dashboard',
        'sidebar' => [
            'new_cta'         => 'New dashboard',
            'new_placeholder' => 'Name…',
            'create'          => 'Create',
            'rename'          => 'Rename',
            'delete'          => 'Delete',
            'set_default'     => 'Make default',
            'default_badge'   => 'default',
            'empty_title'     => 'No dashboards yet',
            'empty_hint'      => 'Create one to start pinning blocks.',
            'error'           => 'Action failed',
            'confirm_delete'  => 'Delete this dashboard? Widgets will be removed.',
        ],
        'card' => [
            'refresh'            => 'Refresh',
            'remove'             => 'Remove',
            'view_source'        => 'View source',
            'unauthorized'       => 'Unauthorized',
            'error'              => 'Error',
            'stale'              => 'Stale',
            'source_missing'     => 'Source missing',
            'no_title'           => 'Untitled widget',
            'refreshing'         => 'Refreshing…',
            'just_now'           => 'just now',
            'inert_actions_hint' => 'Open the chat to run actions.',
        ],
        'header' => [
            'refresh_all'     => 'Refresh all',
            'empty_main'      => 'No dashboard selected',
            'empty_main_hint' => 'Create one from the sidebar to start pinning blocks.',
        ],
        // v2.0 / E6 — pin-from-chat: button on hover + modal.
        'pin' => [
            'cta'                    => 'Pin to dashboard',
            'tooltip'                => 'Pin this block to a dashboard',
            'modal_title'            => 'Pin to dashboard',
            'modal_select_label'     => 'Dashboard',
            'modal_create_inline'    => 'Create new dashboard…',
            'modal_create_name'      => 'Dashboard name',
            'modal_title_label'      => 'Title',
            'modal_title_placeholder'=> 'Optional title…',
            'submit'                 => 'Pin',
            'cancel'                 => 'Cancel',
            'toast_added'            => 'Added to :dashboard',
            'toast_view'             => 'View dashboard',
            'error_dashboard_full'   => 'This dashboard is full. Pick another or unpin first.',
            'error_tool_unpinnable'  => 'This block cannot be pinned (its tool is not pinnable).',
            'error_tool_missing'     => 'The tool that produced this block is no longer registered.',
            'error_generic'          => 'Could not pin to dashboard.',
        ],
        // v2.0 / E7 — chart block default renderer (Chart.js in the dashboard bundle).
        'chart' => [
            'invalid_data'  => 'Chart data is invalid or incomplete.',
            'empty_dataset' => 'No data points to display.',
        ],
        // v2.0 / E8 — kpi block (built-in renderer, works in widget and dashboard).
        'kpi' => [
            'no_value' => '—',
        ],
    ],

    // v2.2 — backend conversational tools (PR-A, PR-B). Messages are emitted
    // by each tool via `ToolResult::error(...)`; the LLM relays them to the
    // user verbatim (don't paraphrase
    // explanations a tool already gives you).
    'add_to_dashboard' => [
        'errors' => [
            'tool_not_found'        => "I don't know a tool called ':tool'. Available ones for your role: :list.",
            'not_pinnable'          => "The action ':tool' does not produce content that can be pinned to the dashboard.",
            'unauthorized'          => "You don't have permission to access that information.",
            'out_of_scope'          => "That information is outside your access scope.",
            'dashboard_not_found'   => "You don't have a dashboard named ':slug'. Your dashboards are: :list.",
            'no_dashboard'          => "You don't have any dashboard yet. Create one at /chatbot/dashboard and ask me again.",
            'cap_reached'           => "Your dashboard ':name' is full (:current/:max). Delete a widget or use another dashboard.",
            'source_args_invalid'   => "I couldn't use the tool ':tool' because some data is missing: :detail. Can you clarify?",
            'source_runtime'        => "The tool ':tool' failed while fetching data: :detail.",
            'no_block'              => "The tool ':tool' did not return any pinnable content this time.",
            'ordinal_out_of_range'  => "The tool ':tool' only returned :count ':type' blocks; there is no :ordinal-th one.",
        ],
        'success' => [
            'card_title'       => '✅ Added to dashboard',
            'card_description' => 'I added **:title** to your dashboard **:dashboard**. View it at :url.',
        ],
    ],
    'edit_widget' => [
        'errors' => [
            'widget_not_found'   => "You don't have a widget with that identifier. If you tell me its title I can find it.",
            'validation'         => ":detail",
            'nothing_to_change'  => "You didn't tell me what to change.",
        ],
        'success' => [
            'card_title'       => '✏️ Widget updated',
            'card_description' => 'I applied the changes to widget **:title**: :summary.',
        ],
    ],
    'delete_widget' => [
        'errors' => [
            'widget_not_found' => "You don't have a widget with that identifier.",
        ],
        'success' => [
            'card_title'       => '🗑️ Widget removed',
            'card_description' => 'I removed the widget **:title** from the dashboard.',
        ],
    ],
    'edit_dashboard' => [
        'errors' => [
            'dashboard_not_found' => "You don't have a dashboard with slug ':slug'.",
            'validation'          => ":detail",
            'nothing_to_change'   => "You didn't tell me what to change.",
        ],
        'success' => [
            'card_title'       => '✏️ Dashboard updated',
            'card_description' => 'I applied the changes to dashboard **:name**: :summary.',
        ],
    ],
    'delete_dashboard' => [
        'errors' => [
            'dashboard_not_found'           => "You don't have a dashboard with slug ':slug'.",
            'would_create_orphan_default'   => "It's your only dashboard. Create another one first or change your request.",
        ],
        'success' => [
            'card_title'                => '🗑️ Dashboard deleted',
            'card_description'          => 'I deleted the dashboard **:name**.',
            'card_description_promoted' => 'I deleted the dashboard **:name**. Your new default dashboard is **:promoted**.',
        ],
    ],

    // v1.1 — titling automático y UI de gestión de conversaciones.
    'untitled_conversation'       => 'Untitled conversation',
    'new_conversation'            => 'New conversation',
    'new_conversation_aria'       => 'Start a new conversation',
    'loading_conversation'        => 'Loading conversation…',
    'failed_to_load_conversation' => 'Failed to load conversation',
    // v2.1 (#3) — shown in the assistant message + banner when the stream
    // emits an `event: error` frame (LLM provider down, network, 5xx).
    'stream_error'                => 'Something went wrong. Please try again.',
    'title'                       => 'Chatbot',
    'open_full_page'              => 'Open full chat page',
];
