<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Exceptions;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use LogicException;

/**
 * Lanzada cuando una tool solicita un scope `team`/`all` y el host no ha
 * registrado un `ScopeResolver` (i.e. el paquete está usando
 * `NullScopeResolver`, que sólo sabe responder `self`).
 *
 * El mensaje guía al integrador hacia el comando
 * `php artisan chatbot:make:scope-resolver`.
 */
class ScopeResolverNotConfiguredException extends LogicException
{
    public function __construct(AccessScope $scope)
    {
        parent::__construct(
            "El scope `{$scope->value}` requiere un ScopeResolver registrado. "
            . "Implementa `Rnkr69\\LaraChatbot\\Authorization\\Contracts\\ScopeResolver` "
            . "y declara la clase en `chatbot.authorization.scope_resolver`. "
            . "Atajo: `php artisan chatbot:make:scope-resolver MyScopeResolver`."
        );
    }
}
