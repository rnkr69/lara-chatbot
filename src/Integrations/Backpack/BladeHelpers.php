<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Integrations\Backpack;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Blade helpers for hosts that use Backpack (cross-host gap part 1).
 *
 * Registers the `@chatbotBackpackContext` directive that renders
 * server-side a `<meta name="chatbot:context" content='...'>` populated
 * with the current CrudPanel's context. The widget reads that meta tag at
 * boot and on every SPA navigation (E14 D14).
 *
 * If Backpack is not installed or there is no resolved panel, the directive
 * emits an empty string — the host can place it in its base layout without
 * breaking non-admin pages.
 */
class BladeHelpers
{
    /**
     * Registers `@chatbotBackpackContext` in the Blade compiler. It is only
     * called from `ChatbotServiceProvider::registerBackpackIntegration()`,
     * and only when Backpack is present — but the directive itself is
     * defensive (it does not require Backpack so as not to fail at render time).
     */
    public static function register(BladeCompiler $blade): void
    {
        $blade->directive('chatbotBackpackContext', static function (): string {
            return '<?php echo \\' . static::class . '::renderMetaTag(); ?>';
        });
    }

    /**
     * Renders the Backpack meta tags that the widget consumes at boot:
     *
     *   - `<meta name="chatbot:context" content='...'>` with the provider's
     *     JSON (page context). Only emitted when there is a resolved panel.
     *   - `<meta name="chatbot:options" content='...'>` with runtime toggles
     *     that only apply to Backpack hosts (DataTables row decoration,
     *     etc.). v1.1.3 (#20).
     *
     * Returns an empty string if none apply.
     */
    public static function renderMetaTag(): string
    {
        $contextTag = static::buildContextTag();
        if ($contextTag === '') {
            // No Backpack panel resolved → emit nothing. The options tag
            // is meaningless outside Backpack pages (and would force a
            // permanent meta on every page that includes the directive).
            return '';
        }

        $tags = [$contextTag];

        $optionsTag = static::buildOptionsTag();
        if ($optionsTag !== '') {
            $tags[] = $optionsTag;
        }

        return implode("\n", $tags);
    }

    /**
     * Builds the `chatbot:context` meta tag from the resolved provider.
     */
    protected static function buildContextTag(): string
    {
        $provider = function_exists('app')
            ? app(BackpackPageContextProvider::class)
            : new BackpackPageContextProvider;

        $context = $provider->currentContext();

        if ($context === null || $context === []) {
            return '';
        }

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        if (! is_string($json)) {
            return '';
        }

        // We wrap the attribute in single quotes; JSON_HEX_QUOT/APOS
        // guarantees that neither `"` nor `'` appears unescaped inside the JSON.
        return '<meta name="chatbot:context" content=\'' . $json . '\'>';
    }

    /**
     * v1.1.3 (#20): builds the `chatbot:options` meta tag carrying runtime
     * toggles for Backpack-specific widget behaviour (DataTables row
     * decoration, etc.). Only emits when at least one toggle is active —
     * the bundle's default behaviour matches "missing tag", so hosts that
     * never include the directive aren't affected.
     */
    protected static function buildOptionsTag(): string
    {
        $payload = [];

        $dtDecoration = function_exists('config')
            ? config('chatbot.backpack.datatables_row_decoration', true)
            : true;

        if (filter_var($dtDecoration, FILTER_VALIDATE_BOOLEAN)) {
            $payload['backpack']['dt_row_decoration'] = true;
        }

        $dtSelectedSync = function_exists('config')
            ? config('chatbot.backpack.datatables_selected_sync', true)
            : true;

        if (filter_var($dtSelectedSync, FILTER_VALIDATE_BOOLEAN)) {
            $payload['backpack']['dt_selected_sync'] = true;
        }

        if ($payload === []) {
            return '';
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
        if (! is_string($json)) {
            return '';
        }

        return '<meta name="chatbot:options" content=\'' . $json . '\'>';
    }
}
