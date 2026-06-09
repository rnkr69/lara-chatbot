<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Integrations\Backpack;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Helpers Blade para hosts que usan Backpack (gap cross-host parte 1).
 *
 * Registra la directive `@chatbotBackpackContext` que renderiza
 * server-side un `<meta name="chatbot:context" content='...'>` poblado
 * con el contexto del CrudPanel actual. El widget lee ese meta tag al
 * boot y en cada navegación SPA (E14 D14).
 *
 * Si Backpack no está instalado o no hay panel resuelto, la directive
 * emite cadena vacía — el host puede colocarla en su layout base sin
 * romper páginas no-admin.
 */
class BladeHelpers
{
    /**
     * Registra `@chatbotBackpackContext` en el compilador Blade. Sólo
     * se llama desde `ChatbotServiceProvider::registerBackpackIntegration()`,
     * y sólo cuando Backpack está presente — pero la directive en sí es
     * defensiva (no requiere Backpack para no fallar en tiempo de render).
     */
    public static function register(BladeCompiler $blade): void
    {
        $blade->directive('chatbotBackpackContext', static function (): string {
            return '<?php echo \\' . static::class . '::renderMetaTag(); ?>';
        });
    }

    /**
     * Renderiza los meta tags Backpack que el widget consume al boot:
     *
     *   - `<meta name="chatbot:context" content='...'>` con el JSON del
     *     provider (page context). Sólo se emite cuando hay panel resuelto.
     *   - `<meta name="chatbot:options" content='...'>` con runtime toggles
     *     que sólo aplican a hosts Backpack (DataTables row decoration,
     *     etc.). v1.1.3 (#20).
     *
     * Devuelve cadena vacía si ninguno aplica.
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

        // Encerramos el atributo entre comillas simples; JSON_HEX_QUOT/APOS
        // garantiza que ni `"` ni `'` aparecen sin escapar dentro del JSON.
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
