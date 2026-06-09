<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View as ViewFactory;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * E17 — `GET /{chatbot.route.prefix}` (default `/chatbot`).
 *
 * Devuelve la vista publishable `chatbot::page` que monta
 * `<chatbot-widget mode="page">` a pantalla completa con sidebar de
 * conversaciones. La vista resuelve dinámicamente:
 *
 *   - `$layout` — nombre del layout host (`chatbot.page.layout`). null si el
 *     host no lo configuró o si la vista no existe (fallback standalone).
 *   - `$section` — nombre de la sección del layout en la que inyectar el
 *     contenido cuando `$layout !== null`. Default `content`.
 *   - `$assetUrl` — URL del bundle `chatbot-widget.js` resuelta vía
 *     `asset(chatbot.widget.asset_path)`.
 *   - `$streamUrl` — URL del endpoint SSE de chat (`route('chatbot.stream')`).
 *   - `$conversationsUrl` — base URL del CRUD de conversaciones; el widget la
 *     usa para sidebar (lista, búsqueda, delete) y para `show` cuando
 *     selecciona una conversación.
 *
 * El controller no exige permiso adicional al middleware del grupo; la
 * autorización por usuario la heredan las llamadas internas
 * (`/chatbot/conversations`, `/chatbot/stream`).
 */
class PageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $layout = $this->resolveLayout();
        $section = (string) config('chatbot.page.section', 'content');
        if ($section === '') {
            $section = 'content';
        }

        $assetPath = (string) config('chatbot.widget.asset_path', 'vendor/chatbot/chatbot-widget.js');
        $assetUrl = asset($assetPath);

        // Two views: `chatbot::page` is fully standalone (no `@extends`);
        // `chatbot::page_layout` extends the host layout. Splitting them is
        // necessary because Blade compiles `@extends(...)` to a footer that
        // runs unconditionally — wrapping it in `@if(...)` would still try to
        // load a null layout and explode with "View [] not found".
        $viewName = $layout !== null ? 'chatbot::page_layout' : 'chatbot::page';

        return view($viewName, [
            'layout'                => $layout,
            'section'               => $section,
            'assetUrl'              => $assetUrl,
            'streamUrl'             => route('chatbot.stream'),
            'conversationsUrl'      => route('chatbot.conversations.index'),
            'theme'                 => (string) config('chatbot.widget.theme', 'auto'),
            'initialConversationId' => $this->resolveInitialConversation($request),
            'i18n'                  => $this->resolveI18n(),
            // v2.1.1 (#26) — "back to app" link for the standalone view, so it
            // is not a navigation-less island.
            'backUrl'               => $this->resolveBackUrl(),
        ]);
    }

    /**
     * v2.1.1 (#26) — URL del enlace "← volver a la app" de la vista
     * standalone del chat. Sólo se pinta si es una string no vacía; null la
     * deja sin enlace. En modo `layout` la navegación la da el host.
     */
    protected function resolveBackUrl(): ?string
    {
        $backUrl = config('chatbot.page.back_url');

        return is_string($backUrl) && $backUrl !== '' ? $backUrl : null;
    }

    /**
     * v2.0 / E9 — bridge PHP → JS para las claves UI del paquete.
     *
     * Devuelve `__('chatbot::chatbot')` como array para que la blade lo
     * `json_encode`e en `<chatbot-widget data-i18n="…">`. El bundle del widget
     * lee este atributo en `connectedCallback` y reemplaza los defaults
     * inline. Si el archivo de lang sólo expone strings (ningún subarray),
     * devolvemos array vacío — los defaults TS siguen ahí.
     */
    protected function resolveI18n(): array
    {
        $payload = trans('chatbot::chatbot');

        return is_array($payload) ? $payload : [];
    }

    /**
     * Allow `/chatbot?conversation_id=X` to deep-link a specific conversation
     * (for sharing, bookmarking, breadcrumb navigation). Validates ownership;
     * invalid / non-owned / missing values silently degrade to null so a
     * recipient who doesn't own the linked conversation still sees the page
     * (rendered with whatever localStorage holds, or empty).
     */
    protected function resolveInitialConversation(Request $request): int|string|null
    {
        $raw = $request->query('conversation_id');
        if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0) {
            return null;
        }

        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $exists = Conversation::query()
            ->forUser($user)
            ->whereKey($id)
            ->exists();

        return $exists ? $id : null;
    }

    /**
     * Devuelve el layout configurado si existe, o null para fallback standalone.
     *
     * Si `chatbot.page.layout` apunta a una vista que NO existe, loguea un
     * warning accionable y degrada a standalone — el host puede haber olvidado
     * publicar el layout o tener un typo. Romper la página en runtime sería
     * peor UX que un fallback con log.
     */
    protected function resolveLayout(): ?string
    {
        $layout = config('chatbot.page.layout');

        if (! is_string($layout) || $layout === '') {
            return null;
        }

        if (! ViewFactory::exists($layout)) {
            Log::warning(sprintf(
                '[chatbot] chatbot.page.layout="%s" no existe en el host. '
                . 'La página /chatbot se renderizará en modo standalone. '
                . 'Verifica el nombre del layout o publica el suyo.',
                $layout,
            ));

            return null;
        }

        return $layout;
    }
}
