<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests;

use Rnkr69\LaraChatbot\ChatbotServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            ChatbotServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Default a GateAuthorizer en tests para no exigir Spatie instalado.
        $app['config']->set('chatbot.authorization.resolver', 'gate');

        // El middleware `web` (CookieEncryption) exige una APP_KEY válida —
        // cualquiera sirve para tests, pero debe ser determinista para que
        // el shared state entre tests no se rompa.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }
}
