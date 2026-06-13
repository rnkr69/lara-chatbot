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
 * Navigates the host UI to a URL or to a named Laravel route.
 *
 * In a SPA (Inertia/Livewire/etc., E13) the widget delegates to the
 * registered navigation adapter so as not to lose the app's state. In an MPA
 * it falls back to `window.location.assign(url)`.
 *
 * Confirmation: `auto` (navigation is the least destructive action in the
 * catalog; the LLM uses it to take the user to the relevant screen).
 *
 * v1.1: when the LLM requests `route` (a named Laravel route) we resolve it
 * server-side and merge the resulting `url` into `frontend_action.args`. The
 * JS primitive only knows `url`; without this resolution the tool's doc said
 * "prefer named routes" but the widget did a silent no-op if `route` arrived
 * without `url` (findings doc #3).
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
            // The LLM passed a direct url: the JS primitive consumes it as-is.
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
            // absolute=false ⇒ returns a relative path (`/orders/42`) that the
            // primitive accepts without touching the current origin.
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
