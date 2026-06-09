<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Services;

use RuntimeException;

/**
 * Lanzada por `PendingActionStore` cuando se intenta transicionar un row
 * desde un estado terminal (rejected/executed/expired) o desde un estado no
 * compatible con la nueva acción. El `ConfirmActionController` la atrapa y
 * la traduce en `409 Conflict`.
 */
class InvalidPendingActionTransition extends RuntimeException {}
