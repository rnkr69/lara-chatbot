<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Services;

use RuntimeException;

/**
 * Thrown by `PendingActionStore` when an attempt is made to transition a row
 * from a terminal state (rejected/executed/expired) or from a state that is
 * not compatible with the new action. The `ConfirmActionController` catches it
 * and translates it into a `409 Conflict`.
 */
class InvalidPendingActionTransition extends RuntimeException {}
