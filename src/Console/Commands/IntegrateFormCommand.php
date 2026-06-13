<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * `php artisan chatbot:integrate-form <view>` (v1.1.1, finding #13.c).
 *
 * Interactive wizard that adds `@chatbotForm(...)` to the first `<form>` in
 * the given Blade. Three schema source modes:
 *
 *  1. FormRequest class FQCN  → `@chatbotForm('id', App\Http\Requests\X::class)`
 *  2. Manual list of fields    → `@chatbotForm('id', [['name'=>'foo','type'=>'text'], ...])`
 *  3. Runtime-extracted        → `@chatbotForm('id')`
 *
 * Diff-and-confirm: shows the change to the user before touching the file.
 * Idempotent: if the form already has `@chatbotForm` or `data-chatbot-form`,
 * skip with an informative message.
 *
 * Does not generate write tools (mentioned in findings as nice-to-have); the
 * user can use `chatbot:make:tool <Name> --type=write` separately.
 */
class IntegrateFormCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:integrate-form
                            {view : Path of the Blade to modify (absolute or relative to base_path).}
                            {--id= : Form ID (default: derived from the view basename).}
                            {--request= : FQCN of a FormRequest to derive the schema from.}
                            {--force : Overwrite even if the form already has another tag.}';

    /** @var string */
    protected $description = 'Add @chatbotForm to a <form> in a host Blade view (interactive).';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $view = (string) $this->argument('view');
        $path = $this->resolvePath($view);

        if (! $this->files->exists($path)) {
            $this->components->error("View `{$path}` does not exist.");
            return self::FAILURE;
        }

        $content = $this->files->get($path);

        if (preg_match('/<form\b([^>]*)>/is', $content, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $this->components->error('Could not find any `<form>` in the view.');
            return self::FAILURE;
        }

        $attrs = $m[1][0];
        $tagStart = (int) $m[0][1];
        $tagEnd   = $tagStart + strlen($m[0][0]);

        if (! $this->option('force') && (
            preg_match('/data-chatbot-form\s*=/i', $attrs) === 1
            || preg_match('/@chatbotForm\s*\(/i', $attrs) === 1
        )) {
            $this->components->info('This form is already integrated (`data-chatbot-form` or `@chatbotForm` detected). Use `--force` to overwrite.');
            return self::SUCCESS;
        }

        $defaultId = $this->option('id') ?: Str::slug(pathinfo($path, PATHINFO_FILENAME));
        $id = (string) $defaultId;

        if ($this->isInteractive()) {
            $id = (string) $this->components->ask('Form ID', $defaultId);
            $id = $id !== '' ? Str::slug($id) : $defaultId;
        }

        // Source mode selection.
        $requestFqcn = (string) $this->option('request');
        $sourceArg   = '';

        if ($requestFqcn !== '') {
            if (! class_exists($requestFqcn)) {
                $this->components->error("The class `{$requestFqcn}` does not exist.");
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
                    $req = trim((string) $this->components->ask('FormRequest FQCN (e.g. App\\Http\\Requests\\ContactRequest)'));
                    if ($req === '' || ! class_exists($req)) {
                        $this->components->error("The class `{$req}` does not exist; aborting.");
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
            if (! $this->components->confirm('Apply?', true)) {
                $this->components->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        $this->files->put($path, $newContent);

        $this->components->info("Integrated `{$id}` into `{$this->relative($path)}`.");
        if ($sourceArg !== '') {
            $this->components->info('Check that the FormRequest has the expected rules() — the schema is derived at runtime when the view renders.');
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
