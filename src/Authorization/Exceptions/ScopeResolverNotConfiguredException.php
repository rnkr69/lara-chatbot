<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Exceptions;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use LogicException;

/**
 * Thrown when a tool requests a `team`/`all` scope and the host has not
 * registered a `ScopeResolver` (i.e. the package is using
 * `NullScopeResolver`, which only knows how to answer `self`).
 *
 * The message guides the integrator toward the
 * `php artisan chatbot:make:scope-resolver` command.
 */
class ScopeResolverNotConfiguredException extends LogicException
{
    public function __construct(AccessScope $scope)
    {
        parent::__construct(
            "The `{$scope->value}` scope requires a registered ScopeResolver. "
            . "Implement `Rnkr69\\LaraChatbot\\Authorization\\Contracts\\ScopeResolver` "
            . "and declare the class in `chatbot.authorization.scope_resolver`. "
            . "Shortcut: `php artisan chatbot:make:scope-resolver MyScopeResolver`."
        );
    }
}
