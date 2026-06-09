<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

/**
 * Navega la UI del host a una URL o a una ruta nombrada de Laravel.
 *
 * En SPA (Inertia/Livewire/etc., E13) el widget delega en el adaptador de
 * navegación registrado para no perder el estado de la app. En MPA cae a
 * `window.location.assign(url)`.
 *
 * Confirmation: `auto` (la navegación es la acción menos destructiva del
 * catálogo; el LLM la usa para llevar al usuario a la pantalla pertinente).
 *
 * v1.1: cuando el LLM pide `route` (ruta nombrada de Laravel) la resolvemos
 * server-side y mergemos la `url` resultante en `frontend_action.args`. La
 * primitiva JS sólo conoce `url`; sin esta resolución la doc del tool decía
 * "prefer named routes" pero el widget hacía silent no-op si llegaba `route`
 * sin `url` (findings doc #3).
 */
class NavigateTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'navigate';
    }

    public function description(): string
    {
        return 'Navigate the user to a different page in the application. Use when the user asks to go to/open/show a specific section, screen or entity. Provide either an absolute URL (`url`) or a named Laravel route (`route` + optional `params`). Prefer named routes if the host exposes them; they survive URL changes. Examples: open the orders list, go to the dashboard, view invoice 1234.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url'    => ['type' => 'string', 'description' => 'Absolute or relative URL. Mutually exclusive with `route`.'],
                'route'  => ['type' => 'string', 'description' => 'Named Laravel route (e.g. `orders.show`).'],
                'params' => ['type' => 'object', 'description' => 'Parameters for the named route. Ignored when `url` is used.'],
            ],
            'required' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $url = isset($args['url']) && is_string($args['url']) ? trim($args['url']) : '';
        if ($url !== '') {
            // El LLM pasó url directa: la primitiva JS la consume tal cual.
            return ToolResult::success([]);
        }

        $route = isset($args['route']) && is_string($args['route']) ? trim($args['route']) : '';
        if ($route === '') {
            return ToolResult::error('validation', 'Provide either `url` or `route`.');
        }

        $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];

        if (! Route::has($route)) {
            return ToolResult::error('runtime', "Named route '{$route}' is not defined in this app.");
        }

        try {
            // absolute=false ⇒ devuelve path relativo (`/orders/42`) que la
            // primitiva acepta sin tocar el origen actual.
            $resolved = route($route, $params, false);
        } catch (UrlGenerationException $e) {
            return ToolResult::error(
                'validation',
                "Missing or invalid params for route '{$route}': " . $e->getMessage(),
            );
        } catch (RouteNotFoundException $e) {
            return ToolResult::error('runtime', "Named route '{$route}' is not defined in this app.");
        } catch (Throwable $e) {
            return ToolResult::error('runtime', "Failed to resolve route '{$route}'.");
        }

        return ToolResult::success(['url' => $resolved]);
    }
}
