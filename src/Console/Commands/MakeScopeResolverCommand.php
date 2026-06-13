<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * `php artisan chatbot:make:scope-resolver MyScopeResolver`
 *
 * Stubs a ScopeResolver class in `app/Chatbot/{name}.php` with the
 * skeleton of the three scopes (self/team/all). The integrator fills in
 * `teamUserIds()` and `allUserIds()` according to their host's hierarchy.
 *
 * The command lives in E04 but the ServiceProvider registers it already in
 * E04 itself (it does not wait for E18) so that the host can generate the
 * resolver during the initial setup.
 */
class MakeScopeResolverCommand extends GeneratorCommand
{
    protected $name = 'chatbot:make:scope-resolver';

    protected $description = 'Stub a chatbot ScopeResolver class in app/Chatbot/.';

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
