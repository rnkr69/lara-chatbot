<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Implementación mínima de `Authenticatable` para los tests del registro
 * y de la cascada de autorización. Evita exigir un User Eloquent o
 * configurar un guard de auth.
 */
class FakeUser implements Authenticatable
{
    public function __construct(
        public readonly int|string $id = 42,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
