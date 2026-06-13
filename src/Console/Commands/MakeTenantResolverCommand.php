<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * `php artisan chatbot:make:tenant-resolver MyTenantResolver`
 *
 * Stubs a `TenantResolver` class in `app/Chatbot/{name}.php` with the
 * skeleton of the contract's only method (`resolveAccessibleTenantIds`)
 * and comments with three typical patterns. The integrator chooses the
 * model and fills it in.
 *
 * Mirror of the `chatbot:make:scope-resolver` pattern — the `InstallCommand`
 * (E18) invokes it as an optional sub-step when the user indicates their
 * host requires tenant scope. The command can also be run standalone if
 * the host adds tenant scope post-install.
 */
class MakeTenantResolverCommand extends GeneratorCommand
{
    protected $name = 'chatbot:make:tenant-resolver';

    protected $description = 'Stub a chatbot TenantResolver class in app/Chatbot/.';

    protected $type = 'TenantResolver';

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/tenant-resolver.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Chatbot';
    }
}
