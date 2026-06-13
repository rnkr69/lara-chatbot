<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\View\Directives;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Blade;
use Rnkr69\LaraChatbot\Validation\RulesToFormSchema;
use Throwable;

/**
 * Blade directive `@chatbotForm($id, $schemaSource = null)` (v1.1.1,
 * finding #13.a).
 *
 * Injects two things in a single declaration:
 *
 *   1. The `data-chatbot-form="<id>"` attribute on the `<form>` that
 *      precedes it.
 *   2. A page context fragment with the form schema via
 *      `<meta name="chatbot:context-form" content='...'>`. The widget
 *      merges it into the main page context on boot.
 *
 * Three schema variants:
 *
 *   - FormRequest FQCN: `@chatbotForm('contact-form', App\Http\Requests\ContactRequest::class)`
 *     → introspects the request's `rules()` and `attributes()` and maps to
 *       a schema with `RulesToFormSchema`.
 *
 *   - Inline array:    `@chatbotForm('contact-form', [
 *         ['name' => 'email', 'type' => 'email', 'required' => true],
 *         ...
 *     ])`
 *     → explicit schema. The simplest and most predictable form.
 *
 *   - No argument:     `@chatbotForm('contact-form')`
 *     → the directive emits the attribute and a lightweight `<script>` that,
 *       post-DOM-ready, scans the `<form>` and reports the present `[name]`/
 *       `[data-chatbot-field]` via `Chatbot.setPageContext`.
 *
 * v1.1.1 limitation: the directive prints `data-chatbot-form` as an
 * HTML fragment that the integrator places inside the `<form>` tag (after
 * the `method` attribute, before the closing `>`). Example:
 *
 *     <form method="post" action="..." @chatbotForm('contact-form')>
 *
 * A version that parses the `<form>` automatically would require
 * a more invasive Blade pre-processor; deferred to v2.
 */
class ChatbotFormDirective
{
    public static function register(): void
    {
        Blade::directive('chatbotForm', static function (string $expression): string {
            // The expression arrives as a Blade string — the render runtime
            // evaluates the PHP expression and passes it to the renderer.
            return '<?php echo \\' . static::class . '::render(' . $expression . '); ?>';
        });
    }

    /**
     * Renders the directive output.
     *
     * @param  string  $id  stable form ID (`contact-form`, etc.).
     * @param  string|array<int|string, mixed>|null  $schemaSource
     */
    public static function render(string $id, string|array|null $schemaSource = null): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }

        $schema = self::resolveSchema($schemaSource);

        $attr = 'data-chatbot-form="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';

        if ($schema === null) {
            // Runtime-extracted variant: emit only the attribute + a one-time
            // helper script that scans the form post-DOM-ready and reports
            // its inputs to Chatbot.setPageContext. Idempotent guard via
            // window.__chatbotFormHelperInstalled so multiple @chatbotForm
            // calls in the same page only install one helper.
            $script = self::runtimeHelperScript();
            return $attr . $script;
        }

        $payload = ['form' => ['id' => $id, 'fields' => $schema]];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        if (! is_string($json)) {
            return $attr;
        }

        $meta = "<meta name=\"chatbot:context-form\" content='" . $json . "'>";

        return $attr . $meta;
    }

    /**
     * @param  string|array<int|string, mixed>|null  $source
     * @return list<array<string, mixed>>|null  null = runtime-extract
     */
    protected static function resolveSchema(string|array|null $source): ?array
    {
        if ($source === null) {
            return null;
        }

        if (is_array($source)) {
            // Inline list of {name, type, required?, options?, label?}.
            $out = [];
            foreach ($source as $entry) {
                if (! is_array($entry) || ! isset($entry['name']) || ! is_string($entry['name'])) {
                    continue;
                }
                $out[] = $entry;
            }
            return $out;
        }

        // String → FormRequest FQCN. Try to instantiate and read rules().
        if (! class_exists($source)) {
            return [];
        }

        try {
            $instance = function_exists('app') ? app($source) : new $source;
        } catch (Throwable) {
            try {
                $instance = new $source;
            } catch (Throwable) {
                return [];
            }
        }

        if (! is_object($instance) || ! method_exists($instance, 'rules')) {
            return [];
        }

        try {
            $rules = $instance->rules();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($rules)) {
            return [];
        }

        $labels = [];
        if ($instance instanceof FormRequest && method_exists($instance, 'attributes')) {
            try {
                $a = $instance->attributes();
                if (is_array($a)) {
                    foreach ($a as $k => $v) {
                        if (is_string($k) && is_scalar($v)) {
                            $labels[$k] = (string) $v;
                        }
                    }
                }
            } catch (Throwable) { /* keep labels empty */ }
        }

        $mapper = new RulesToFormSchema;
        return $mapper->fromRules($rules, $labels);
    }

    /**
     * Lightweight script that scans the tagged `<form>` and publishes its
     * runtime schema. It is injected only ONCE per page (idempotency via a flag
     * on `window`).
     */
    protected static function runtimeHelperScript(): string
    {
        return <<<'HTML'
<script data-chatbot-form-helper>
(function () {
  if (window.__chatbotFormHelperInstalled) return;
  window.__chatbotFormHelperInstalled = true;
  function publish() {
    var forms = document.querySelectorAll('form[data-chatbot-form]');
    if (forms.length === 0) return;
    var schemas = {};
    forms.forEach(function (form) {
      var id = form.getAttribute('data-chatbot-form');
      if (!id) return;
      var fields = [];
      form.querySelectorAll('[name], [data-chatbot-field]').forEach(function (el) {
        var n = el.getAttribute('data-chatbot-field') || el.getAttribute('name');
        if (!n) return;
        var t = (el.getAttribute('type') || el.tagName || '').toLowerCase();
        var entry = { name: n, type: t === 'select' || el.tagName === 'SELECT' ? 'select' : t };
        if (el.hasAttribute('required')) entry.required = true;
        fields.push(entry);
      });
      schemas[id] = { id: id, fields: fields };
    });
    var ctx = (window.Chatbot && window.Chatbot.setPageContext) ? window.Chatbot.setPageContext : null;
    if (!ctx) return;
    var first = Object.keys(schemas)[0];
    if (first) ctx({ form: schemas[first] });
  }
  if (window.Chatbot && window.Chatbot.whenReady) {
    window.Chatbot.whenReady(publish);
  } else {
    document.addEventListener('chatbot:ready', publish, { once: true });
    if (document.readyState !== 'loading') setTimeout(publish, 50);
    else document.addEventListener('DOMContentLoaded', publish, { once: true });
  }
})();
</script>
HTML;
    }
}
