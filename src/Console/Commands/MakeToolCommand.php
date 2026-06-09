<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php artisan chatbot:make:tool ListMyInvoices [--type=read|write]`
 *
 * Stubea una clase que extiende `BaseBackendTool` en
 * `app/Chatbot/Tools/{Name}.php`. Con `--type=read` (default) se genera el
 * esqueleto de una tool que sólo consulta; con `--type=write`, una tool de
 * escritura con TODOs para idempotencia y audit log.
 */
class MakeToolCommand extends GeneratorCommand
{
    protected $name = 'chatbot:make:tool';

    protected $description = 'Stubea una clase BackendTool en app/Chatbot/Tools/.';

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
            ['type', null, InputOption::VALUE_OPTIONAL, 'Tipo de tool: read (default) o write.', 'read'],
        ];
    }

    /**
     * Convierte el FQCN solicitado en `name()` snake_case razonable. Lo
     * inyecta en el stub para que el integrador no tenga que pensarlo.
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $shortName = class_basename($name);
        $snakeName = \Illuminate\Support\Str::snake($shortName);

        return str_replace('{{ tool_name }}', $snakeName, $stub);
    }
}
