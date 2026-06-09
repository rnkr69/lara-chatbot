<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * `php artisan chatbot:make:tenant-resolver MyTenantResolver`
 *
 * Stubea una clase `TenantResolver` en `app/Chatbot/{name}.php` con el
 * esqueleto del único método del contrato (`resolveAccessibleTenantIds`)
 * y comentarios con tres patrones típicos. El integrador
 * elige el modelo y rellena.
 *
 * Mirror del patrón `chatbot:make:scope-resolver` — el `InstallCommand`
 * (E18) lo invoca como sub-paso opcional cuando el usuario indica que su
 * host requiere tenant scope. El comando también se puede ejecutar suelto
 * si el host añade tenant scope post-install.
 */
class MakeTenantResolverCommand extends GeneratorCommand
{
    protected $name = 'chatbot:make:tenant-resolver';

    protected $description = 'Stubea una clase TenantResolver para el chatbot en app/Chatbot/.';

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
