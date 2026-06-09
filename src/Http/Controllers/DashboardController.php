<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View as ViewFactory;
use Rnkr69\LaraChatbot\Models\Dashboard;

/**
 * E4 — `GET /{chatbot.route.prefix}/dashboard` (default `/chatbot/dashboard`).
 *
 * Sirve la vista publishable `chatbot::dashboard` que el bundle separado del
 * E5 (`chatbot-dashboard.js`) montará. En E4 sólo entregamos el root + las
 * URLs de la API JSON (E4) + el slug por defecto del usuario.
 *
 * Patrón idéntico a `PageController` (E17, D16):
 *
 *   - Si `chatbot.dashboard.layout` es string Y `View::exists($layout)` →
 *     `chatbot::dashboard_layout` (`@extends($layout) @section($section)`).
 *   - Si null o la vista no existe (con log warning) → `chatbot::dashboard`
 *     standalone (HTML completo desde el paquete).
 *
 * Param opcional `?dashboard={slug}` deep-linkea un dashboard concreto. Si
 * el slug no existe o no pertenece al usuario, **NO 404**: el frontend (E5)
 * pinta el empty state / cae al default. La vista HTML siempre 200 — sin
 * dashboards el usuario ve un CTA "crea tu primer panel".
 *
 * v2.1.1 (#26): si `chatbot.dashboard.mount_widget` (default true), ambas
 * vistas montan el `<chatbot-widget>` flotante para que el usuario pueda
 * pinear desde el propio dashboard; y la vista standalone pinta un enlace
 * "volver a la app" cuando `chatbot.dashboard.back_url` está seteado.
 *
 * La autorización por usuario la hereda del middleware del grupo
 * (`config('chatbot.route.middleware')` = `['web', 'auth']`).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $layout  = $this->resolveLayout();
        $section = (string) config('chatbot.dashboard.section', 'content');
        if ($section === '') {
            $section = 'content';
        }

        $assetPath = (string) config('chatbot.dashboard.asset_path', 'vendor/chatbot/chatbot-dashboard.js');
        $assetUrl  = asset($assetPath);

        // Two views: `chatbot::dashboard` is fully standalone (no
        // `@extends`); `chatbot::dashboard_layout` extends the host layout.
        // Splitting them is necessary because Blade compiles `@extends(...)`
        // to a footer that runs unconditionally — wrapping it in `@if(...)`
        // would still try to load a null layout and explode with "View []
        // not found" (mismo patrón que E17, ver D16).
        $viewName = $layout !== null ? 'chatbot::dashboard_layout' : 'chatbot::dashboard';

        $chartRenderer = (string) config('chatbot.dashboard.chart_renderer', 'chartjs');
        if (! in_array($chartRenderer, ['chartjs', 'none'], true)) {
            $chartRenderer = 'chartjs';
        }

        // v2.1.1 (#26) — mount the floating widget on the dashboard so the
        // user can pin from here (the page whose job is to *collect* pinned
        // blocks was the one place you couldn't generate them). The widget
        // bundle URL is only resolved when the feature is on.
        $mountWidget = filter_var(config('chatbot.dashboard.mount_widget', true), FILTER_VALIDATE_BOOLEAN);

        $defaultSlug = $this->resolveDefaultSlug($request);

        return view($viewName, [
            'layout'         => $layout,
            'section'        => $section,
            'assetUrl'       => $assetUrl,
            'dashboardsUrl'  => route('chatbot.dashboards.index'),
            'theme'          => (string) config('chatbot.widget.theme', 'auto'),
            'defaultSlug'    => $defaultSlug,
            // v2.2 — page_context auto-inject. JSON con slug/name/is_default/
            // widgets[] del dashboard que se abre inicialmente. El bundle JS
            // lo lee on-boot y llama `window.Chatbot.setPageContext({dashboard:
            // {...}})` para que el LLM pueda resolver "el widget de KPIs"
            // → widget_id sin fuzzy matching. Sin defaultSlug → array vacío.
            'dashboardContext' => $this->resolveDashboardContext($request, $defaultSlug),
            'chartRenderer'  => $chartRenderer,
            'useBootstrap'   => $this->resolveUseBootstrap($layout),
            // v2.1 (#17) — gated the per-widget "View source" (👁) button.
            // v2.1.3: the button is gone; we keep stamping `data-debug` from
            // `config('app.debug')` for forward-compat with any future host
            // affordance, but the bundle no longer reads it.
            'debug'          => (bool) config('app.debug', false),
            'i18n'           => $this->resolveI18n(),
            // v2.1.1 (#26) — floating widget mount + standalone "back to app" link.
            'mountWidget'      => $mountWidget,
            'widgetAssetUrl'   => $mountWidget
                ? asset((string) config('chatbot.widget.asset_path', 'vendor/chatbot/chatbot-widget.js'))
                : null,
            'streamUrl'        => route('chatbot.stream'),
            'conversationsUrl' => route('chatbot.conversations.index'),
            'backUrl'          => $this->resolveBackUrl(),
            // v2.1.3 (#34) — optional host view to `@include` inside the
            // dashboard section. Replaces the broken `chatbot_dashboard_extras`
            // stack (the stack lived inside an already-captured @section, so
            // pushes from the host's `$layout` view never reached it).
            'extrasView'       => $this->resolveExtrasView(),
        ]);
    }

    /**
     * v2.2 — Estructura del `data-dashboard-context` que el bundle JS lee al
     * arrancar y traduce a `Chatbot.setPageContext({dashboard: {...}})`. Le da
     * al LLM resolución directa de "el widget de KPIs" → `widget_id` desde el
     * `page_context.dashboard.widgets`, sin pasar por fuzzy matching o
     * alucinaciones (las edit/delete tools de PR-B exigen ID, no título).
     *
     * Cap binario: si los widgets exceden `chatbot.limits.page_context_kb`
     * (default 16 KB) tras serializar, se reduce el subset a `id` + `title`
     * por widget — lo suficiente para que el LLM resuelva sin perder cuerpo
     * en el system prompt. Por debajo del cap se incluye `block_type`,
     * `position`, `refresh_policy`, `last_refresh_status` para que el LLM
     * pueda razonar también sobre estado y geometría.
     *
     * Devuelve `[]` (en lugar de null) cuando no hay dashboard a inyectar —
     * la blade emite el attribute con `[]` y el bundle JS detecta el vacío
     * para no setear page_context. Esto evita que el LLM "resuelva" widgets
     * que ya no existen.
     *
     * @return array<string, mixed>
     */
    protected function resolveDashboardContext(Request $request, ?string $slug): array
    {
        $user = $request->user();

        if (! $user instanceof Model || $slug === null) {
            return [];
        }

        /** @var Dashboard|null $dashboard */
        $dashboard = Dashboard::query()
            ->forUser($user)
            ->where('slug', $slug)
            ->with(['widgets' => function ($q): void {
                $q->orderBy('order_index')->orderBy('id');
            }])
            ->first();

        if ($dashboard === null) {
            return [];
        }

        $widgets = $dashboard->widgets->map(static function ($w): array {
            return [
                'id'                  => (int) $w->id,
                'title'               => $w->title,
                'block_type'          => (string) $w->block_type,
                'position'            => is_array($w->position) ? $w->position : null,
                'refresh_policy'      => $w->refresh_policy?->value,
                'last_refresh_status' => $w->last_refresh_status?->value,
            ];
        })->all();

        $context = [
            'slug'       => (string) $dashboard->slug,
            'name'       => (string) $dashboard->name,
            'is_default' => (bool) $dashboard->is_default,
            'widgets'    => $widgets,
        ];

        // Cap: el page_context completo del chat tiene un tope binario para
        // no inflar el system prompt. Si la lista de widgets nos hace pasar
        // ese cap, degradamos a `id` + `title` (suficiente para que el LLM
        // matchee títulos y emita widget_ids). Mejor un page_context
        // funcionalmente útil pero limitado que ninguno.
        $limitKb = (int) config('chatbot.limits.page_context_kb', 16);
        $limit   = max(1, $limitKb) * 1024;

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && strlen($encoded) > $limit) {
            $context['widgets'] = $dashboard->widgets->map(static fn ($w): array => [
                'id'    => (int) $w->id,
                'title' => $w->title,
            ])->all();
            $context['widgets_truncated'] = true;
        }

        return $context;
    }

    /**
     * v2.1.3 (#34) — name of an optional host view that `dashboard_layout`
     * `@include`s inside the section, just below the dashboard root. Same
     * validation/degrade policy as `resolveLayout()` so a typo logs a
     * warning rather than crashing the dashboard.
     */
    protected function resolveExtrasView(): ?string
    {
        $extras = config('chatbot.dashboard.extras_view');

        if (! is_string($extras) || $extras === '') {
            return null;
        }

        if (! ViewFactory::exists($extras)) {
            Log::warning(sprintf(
                '[chatbot] chatbot.dashboard.extras_view="%s" no existe en el host. '
                . 'La página /chatbot/dashboard se renderizará sin extras. '
                . 'Verifica el nombre de la vista o quita la clave del config.',
                $extras,
            ));

            return null;
        }

        return $extras;
    }

    /**
     * v2.1.1 (#26) — URL del enlace "← volver a la app" de la vista
     * standalone. Sólo se pinta si es una string no vacía; null deja la
     * página sin enlace. En modo `layout` la navegación la da el chrome del
     * host, así que la vista layout lo ignora.
     */
    protected function resolveBackUrl(): ?string
    {
        $backUrl = config('chatbot.dashboard.back_url');

        return is_string($backUrl) && $backUrl !== '' ? $backUrl : null;
    }

    /**
     * v2.1 / #19 — decide si el dashboard debe usar las primitivas de
     * Bootstrap del host (cuando está disponible) en vez del CSS propio del
     * paquete para los block renderers (`table`/`card`/`list`).
     *
     * `chatbot.backpack.use_bootstrap`:
     *   - `true`/`false` (o sus strings) → fuerza el modo.
     *   - `'auto'` (default) → true sólo si el dashboard está en modo
     *     `layout` (hereda el `<head>` del host, donde vive el Bootstrap de
     *     Backpack) Y el paquete Backpack está instalado. El modo standalone
     *     es una página HTML propia del paquete sin `<head>` del host — ahí
     *     nunca hay Bootstrap, así que `auto` siempre resuelve a false.
     */
    protected function resolveUseBootstrap(?string $layout): bool
    {
        $setting = config('chatbot.backpack.use_bootstrap', 'auto');

        if (is_bool($setting)) {
            return $setting;
        }

        $normalized = is_string($setting) ? strtolower(trim($setting)) : '';

        if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
            return false;
        }

        // 'auto' (o cualquier valor no reconocido): Bootstrap sólo es
        // alcanzable en modo layout y con el paquete Backpack instalado.
        return $layout !== null
            && class_exists('Backpack\\CRUD\\BackpackServiceProvider');
    }

    /**
     * v2.0 / E9 — bridge PHP → JS, idéntico al de `PageController`. La blade
     * lo `json_encode`a en `data-i18n` sobre `#chatbot-dashboard-root`; el
     * bundle del dashboard drena el subtree `dashboard.*` y `dashboard.kpi.*`
     * desde aquí.
     */
    protected function resolveI18n(): array
    {
        $payload = trans('chatbot::chatbot');

        return is_array($payload) ? $payload : [];
    }

    /**
     * Resuelve el slug del dashboard a abrir. Prioridad:
     *
     *   1. `?dashboard={slug}` válido y propio del usuario.
     *   2. `is_default=true` del usuario.
     *   3. null — sin dashboards (la vista pinta empty state vía E5).
     *
     * Igual que `PageController::resolveInitialConversation()`, si el slug
     * de la query no es propio degradamos a null (no 404) — el deep-link
     * compartido por error no rompe la página.
     */
    protected function resolveDefaultSlug(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            return null;
        }

        $requested = $request->query('dashboard');

        if (is_string($requested) && $requested !== '') {
            $exists = Dashboard::query()
                ->forUser($user)
                ->where('slug', $requested)
                ->exists();

            if ($exists) {
                return $requested;
            }
        }

        /** @var Dashboard|null $default */
        $default = Dashboard::query()
            ->forUser($user)
            ->default()
            ->first();

        return $default?->slug;
    }

    /**
     * Mismo patrón que `PageController::resolveLayout()`. Si la vista del
     * layout configurado NO existe, loguea warning y degrada a standalone
     * en vez de romper la página en runtime.
     */
    protected function resolveLayout(): ?string
    {
        $layout = config('chatbot.dashboard.layout');

        if (! is_string($layout) || $layout === '') {
            return null;
        }

        if (! ViewFactory::exists($layout)) {
            Log::warning(sprintf(
                '[chatbot] chatbot.dashboard.layout="%s" no existe en el host. '
                . 'La página /chatbot/dashboard se renderizará en modo standalone. '
                . 'Verifica el nombre del layout o publica el suyo.',
                $layout,
            ));

            return null;
        }

        return $layout;
    }
}
