<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rnkr69\LaraChatbot\Http\Controllers\ApiDashboardController;
use Rnkr69\LaraChatbot\Http\Controllers\ApiDashboardWidgetController;
use Rnkr69\LaraChatbot\Http\Controllers\ChatController;
use Rnkr69\LaraChatbot\Http\Controllers\ConfirmActionController;
use Rnkr69\LaraChatbot\Http\Controllers\ConversationController;
use Rnkr69\LaraChatbot\Http\Controllers\DashboardController;
use Rnkr69\LaraChatbot\Http\Controllers\PageController;
use Rnkr69\LaraChatbot\Http\Controllers\PendingActionController;
use Rnkr69\LaraChatbot\Http\Controllers\SuggestedPromptsController;

// Las rutas reales se definen en E09 (POST /chatbot/stream),
// E10 (CRUD /chatbot/conversations), E16 (POST /chatbot/actions/{action}/confirm)
// y E17 (GET /chatbot). El ServiceProvider envuelve este archivo en el grupo
// configurado por chatbot.route (prefix, middleware, domain) — ver ROADMAP §3.5.

// E09 — Endpoint SSE de chat. Hereda prefix/middleware del grupo del provider.
Route::post('/stream', [ChatController::class, 'stream'])->name('stream');

// E10 — CRUD básico de conversaciones del usuario autenticado.
Route::get('/conversations', [ConversationController::class, 'index'])
    ->name('conversations.index');
Route::post('/conversations', [ConversationController::class, 'store'])
    ->name('conversations.store');
Route::get('/conversations/{id}', [ConversationController::class, 'show'])
    ->whereNumber('id')
    ->name('conversations.show');
Route::delete('/conversations/{id}', [ConversationController::class, 'destroy'])
    ->whereNumber('id')
    ->name('conversations.destroy');

// E16 — Confirm/reject/report de pending actions de frontend tools
// `confirmation=confirm|manual`. El path param es el `action_id` (uuid)
// que viaja en el evento SSE `frontend_action`. La autorización por
// ownership de la conversación parent vive en el controller (404-no-403).
Route::post('/actions/{action}/confirm', ConfirmActionController::class)
    ->where('action', '[0-9a-fA-F-]{36}')
    ->name('actions.confirm');

// v1.1 — listado de pending actions del usuario para que el widget rehidrate
// banners `confirm`/`manual` que quedaron sin resolver tras una navegación
// MPA. Filtros: `status=pending` (default) y `conversation_id=N`.
Route::get('/actions', [PendingActionController::class, 'index'])
    ->name('actions.index');

// v1.1.1 — suggested prompts del empty state del widget. Server-side resuelve
// closure si la config es role-based.
Route::get('/suggested-prompts', SuggestedPromptsController::class)
    ->name('suggested-prompts');

// E17 — Página dedicada de chat. La ruta sólo se registra si
// `chatbot.page.enabled === true`; hosts que sólo quieran el widget
// flotante pueden silenciar `/chatbot` sin tocar middleware ni layouts.
if ((bool) config('chatbot.page.enabled', true)) {
    Route::get('/', PageController::class)->name('page');
}

// v2.0 / E4 — Personal Dashboard. Todo el conjunto se registra sólo si
// `chatbot.dashboard.enabled === true`. Hosts que no usan el dashboard
// pueden silenciarlo entero sin que aparezcan las rutas (404 nativo).
//
// Rutas:
//   - GET    /chatbot/dashboard                          Vista HTML.
//   - GET    /chatbot/dashboards                         JSON CRUD list.
//   - POST   /chatbot/dashboards                         JSON CRUD create.
//   - GET    /chatbot/dashboards/{slug}                  JSON CRUD show.
//   - PATCH  /chatbot/dashboards/{slug}                  JSON CRUD update.
//   - DELETE /chatbot/dashboards/{slug}                  JSON CRUD destroy.
//   - POST   /chatbot/dashboards/{slug}/widgets          JSON pin widget.
//   - PATCH  /chatbot/dashboards/{slug}/widgets/{id}     JSON move/resize/retitle.
//   - POST   /chatbot/dashboards/{slug}/widgets/{id}/refresh
//                                                       JSON manual replay.
//   - DELETE /chatbot/dashboards/{slug}/widgets/{id}     JSON unpin.
//   - POST   /chatbot/dashboards/{slug}/refresh          SSE bulk replay.
if ((bool) config('chatbot.dashboard.enabled', true)) {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/dashboards', [ApiDashboardController::class, 'index'])
        ->name('dashboards.index');
    Route::post('/dashboards', [ApiDashboardController::class, 'store'])
        ->name('dashboards.store');
    Route::get('/dashboards/{slug}', [ApiDashboardController::class, 'show'])
        ->name('dashboards.show');
    Route::patch('/dashboards/{slug}', [ApiDashboardController::class, 'update'])
        ->name('dashboards.update');
    Route::delete('/dashboards/{slug}', [ApiDashboardController::class, 'destroy'])
        ->name('dashboards.destroy');

    Route::post('/dashboards/{slug}/widgets', [ApiDashboardWidgetController::class, 'store'])
        ->name('dashboards.widgets.store');
    Route::patch('/dashboards/{slug}/widgets/{id}', [ApiDashboardWidgetController::class, 'update'])
        ->whereNumber('id')
        ->name('dashboards.widgets.update');
    Route::post('/dashboards/{slug}/widgets/{id}/refresh', [ApiDashboardWidgetController::class, 'refresh'])
        ->whereNumber('id')
        ->name('dashboards.widgets.refresh');
    Route::delete('/dashboards/{slug}/widgets/{id}', [ApiDashboardWidgetController::class, 'destroy'])
        ->whereNumber('id')
        ->name('dashboards.widgets.destroy');

    Route::post('/dashboards/{slug}/refresh', [ApiDashboardWidgetController::class, 'refreshAll'])
        ->name('dashboards.refresh');
}
