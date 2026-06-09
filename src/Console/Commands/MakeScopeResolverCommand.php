<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * `php artisan chatbot:make:scope-resolver MyScopeResolver`
 *
 * Stubea una clase ScopeResolver en `app/Chatbot/{name}.php` con el
 * esqueleto de los tres scopes (self/team/all). El integrador rellena
 * `teamUserIds()` y `allUserIds()` según la jerarquía de su host.
 *
 * El comando vive en E04 pero el ServiceProvider lo registra ya en E04
 * mismo (no espera a E18) para que el host pueda generar el resolver
 * durante el setup inicial.
 */
class MakeScopeResolverCommand extends GeneratorCommand
{
    protected $name = 'chatbot:make:scope-resolver';

    protected $description = 'Stubea una clase ScopeResolver para el chatbot en app/Chatbot/.';

    protected $type = 'ScopeResolver';

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/scope-resolver.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Chatbot';
    }
}
