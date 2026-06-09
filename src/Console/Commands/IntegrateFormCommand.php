<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * `php artisan chatbot:integrate-form <view>` (v1.1.1, finding #13.c).
 *
 * Wizard interactivo que añade `@chatbotForm(...)` al primer `<form>` del
 * Blade indicado. Tres source modes de schema:
 *
 *  1. FormRequest class FQCN  → `@chatbotForm('id', App\Http\Requests\X::class)`
 *  2. Manual list of fields    → `@chatbotForm('id', [['name'=>'foo','type'=>'text'], ...])`
 *  3. Runtime-extracted        → `@chatbotForm('id')`
 *
 * Diff-y-confirm: muestra el cambio al usuario antes de tocar el archivo.
 * Idempotente: si el form ya tiene `@chatbotForm` o `data-chatbot-form`,
 * skip con mensaje informativo.
 *
 * No genera write tools (mencionado en findings como nice-to-have); el
 * usuario puede usar `chatbot:make:tool <Name> --type=write` por separado.
 */
class IntegrateFormCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:integrate-form
                            {view : Ruta del Blade a modificar (absoluta o relativa al base_path).}
                            {--id= : ID del form (default: derivado del basename de la view).}
                            {--request= : FQCN de un FormRequest para derivar el schema.}
                            {--force : Sobrescribe aunque el form ya tenga otro tag.}';

    /** @var string */
    protected $description = 'Añade @chatbotForm a un <form> de una view Blade del host (interactivo).';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $view = (string) $this->argument('view');
        $path = $this->resolvePath($view);

        if (! $this->files->exists($path)) {
            $this->components->error("View `{$path}` no existe.");
            return self::FAILURE;
        }

        $content = $this->files->get($path);

        if (preg_match('/<form\b([^>]*)>/is', $content, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $this->components->error('No encontré ningún `<form>` en la view.');
            return self::FAILURE;
        }

        $attrs = $m[1][0];
        $tagStart = (int) $m[0][1];
        $tagEnd   = $tagStart + strlen($m[0][0]);

        if (! $this->option('force') && (
            preg_match('/data-chatbot-form\s*=/i', $attrs) === 1
            || preg_match('/@chatbotForm\s*\(/i', $attrs) === 1
        )) {
            $this->components->info('Este form ya está integrado (`data-chatbot-form` o `@chatbotForm` detectado). Usa `--force` para sobrescribir.');
            return self::SUCCESS;
        }

        $defaultId = $this->option('id') ?: Str::slug(pathinfo($path, PATHINFO_FILENAME));
        $id = (string) $defaultId;

        if ($this->isInteractive()) {
            $id = (string) $this->components->ask('ID del form', $defaultId);
            $id = $id !== '' ? Str::slug($id) : $defaultId;
        }

        // Source mode selection.
        $requestFqcn = (string) $this->option('request');
        $sourceArg   = '';

        if ($requestFqcn !== '') {
            if (! class_exists($requestFqcn)) {
                $this->components->error("La clase `{$requestFqcn}` no existe.");
                return self::FAILURE;
            }
            $sourceArg = ', ' . $requestFqcn . '::class';
        } elseif ($this->isInteractive()) {
            $choice = $this->components->choice(
                'Schema source',
                [
                    '1' => 'Derive from a FormRequest class (recommended if applicable)',
                    '2' => 'Manual list of field names + types (you edit by hand after)',
                    '3' => 'Auto-extract from <input> elements at runtime (no schema upfront)',
                ],
                '3',
            );
            switch ($choice) {
                case '1':
                case 'Derive from a FormRequest class (recommended if applicable)':
                    $req = trim((string) $this->components->ask('FormRequest FQCN (ej. App\\Http\\Requests\\ContactRequest)'));
                    if ($req === '' || ! class_exists($req)) {
                        $this->components->error("La clase `{$req}` no existe; abortando.");
                        return self::FAILURE;
                    }
                    $sourceArg = ', ' . $req . '::class';
                    break;
                case '2':
                case 'Manual list of field names + types (you edit by hand after)':
                    $sourceArg = ", [\n        ['name' => 'TODO', 'type' => 'text', 'required' => true],\n    ]";
                    break;
                default:
                    $sourceArg = '';
            }
        }

        $directive = "@chatbotForm('{$id}'{$sourceArg})";

        $newAttrs = rtrim($attrs);
        if (! str_ends_with($newAttrs, ' ')) {
            $newAttrs .= ' ';
        }
        $newAttrs .= $directive;

        $newTag = '<form' . $newAttrs . '>';
        $newContent = substr_replace($content, $newTag, $tagStart, $tagEnd - $tagStart);

        if ($this->isInteractive()) {
            $this->newLine();
            $this->line('  <comment>Diff preview:</comment>');
            $this->line('  - <fg=red>' . trim($m[0][0]) . '</>');
            $this->line('  + <fg=green>' . trim($newTag) . '</>');
            $this->newLine();
            if (! $this->components->confirm('¿Aplicar?', true)) {
                $this->components->info('Cancelado.');
                return self::SUCCESS;
            }
        }

        $this->files->put($path, $newContent);

        $this->components->info("Integrado `{$id}` en `{$this->relative($path)}`.");
        if ($sourceArg !== '') {
            $this->components->info('Revisa que el FormRequest tenga las rules() esperadas — el schema se deriva en runtime cuando renderiza la view.');
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $view): string
    {
        if ($this->files->isFile($view)) {
            return $view;
        }
        if (function_exists('base_path')) {
            $candidate = base_path($view);
            if ($this->files->isFile($candidate)) {
                return $candidate;
            }
        }
        // Try resource_path interpretation: 'contact' → resources/views/contact.blade.php.
        if (function_exists('resource_path')) {
            $candidate = resource_path('views/' . str_replace('.', '/', $view) . '.blade.php');
            if ($this->files->isFile($candidate)) {
                return $candidate;
            }
        }
        return $view;
    }

    private function relative(string $absolute): string
    {
        if (! function_exists('base_path')) {
            return $absolute;
        }
        $base = base_path() . DIRECTORY_SEPARATOR;
        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }

    private function isInteractive(): bool
    {
        return ! $this->option('no-interaction');
    }
}
