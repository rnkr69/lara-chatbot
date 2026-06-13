<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php artisan chatbot:make:tool ListMyInvoices [--type=read|write]`
 *
 * Stubs a class that extends `BaseBackendTool` in
 * `app/Chatbot/Tools/{Name}.php`. With `--type=read` (default) it generates
 * the skeleton of a query-only tool; with `--type=write`, a write tool
 * with TODOs for idempotency and audit log.
 */
class MakeToolCommand extends GeneratorCommand
{
    protected $name = 'chatbot:make:tool';

    protected $description = 'Stub a BackendTool class in app/Chatbot/Tools/.';

    protected $type = 'BackendTool';

    protected function getStub(): string
    {
        $type = $this->option('type') ?: 'read';

        return match ($type) {
            'write' => __DIR__ . '/stubs/tool-write.stub',
            default => __DIR__ . '/stubs/tool-read.stub',
        };
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Chatbot\Tools';
    }

    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string, 4?: string|null}>
     */
    protected function getOptions(): array
    {
        return [
            ['type', null, InputOption::VALUE_OPTIONAL, 'Tool type: read (default) or write.', 'read'],
        ];
    }

    /**
     * Converts the requested FQCN into a reasonable snake_case `name()`. It
     * injects it into the stub so the integrator does not have to think about it.
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $shortName = class_basename($name);
        $snakeName = \Illuminate\Support\Str::snake($shortName);

        return str_replace('{{ tool_name }}', $snakeName, $stub);
    }
}
