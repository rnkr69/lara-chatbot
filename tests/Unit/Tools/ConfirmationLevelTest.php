<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

it('exposes auto, confirm and manual cases with backed string values', function () {
    expect(ConfirmationLevel::Auto->value)->toBe('auto')
        ->and(ConfirmationLevel::Confirm->value)->toBe('confirm')
        ->and(ConfirmationLevel::Manual->value)->toBe('manual');
});

it('round-trips from string via tryFrom', function () {
    expect(ConfirmationLevel::tryFrom('auto'))->toBe(ConfirmationLevel::Auto)
        ->and(ConfirmationLevel::tryFrom('confirm'))->toBe(ConfirmationLevel::Confirm)
        ->and(ConfirmationLevel::tryFrom('manual'))->toBe(ConfirmationLevel::Manual)
        ->and(ConfirmationLevel::tryFrom('bogus'))->toBeNull();
});
