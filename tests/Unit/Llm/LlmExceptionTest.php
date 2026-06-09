<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Llm\Exceptions\LlmException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Exceptions\PrismServerException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;

it('classifies PrismRateLimitedException as reason=rate_limit', function () {
    $wrapped = LlmException::fromPrism(PrismRateLimitedException::make([]));

    expect($wrapped->reason)->toBe('rate_limit')
        ->and($wrapped->getPrevious())->toBeInstanceOf(PrismRateLimitedException::class);
});

it('classifies PrismProviderOverloadedException as reason=overloaded', function () {
    $wrapped = LlmException::fromPrism(new PrismProviderOverloadedException('overloaded'));

    expect($wrapped->reason)->toBe('overloaded');
});

it('classifies PrismRequestTooLargeException as reason=request_too_large', function () {
    $wrapped = LlmException::fromPrism(new PrismRequestTooLargeException('too big'));

    expect($wrapped->reason)->toBe('request_too_large');
});

it('classifies PrismStreamDecodeException as reason=stream_decode', function () {
    $wrapped = LlmException::fromPrism(
        new PrismStreamDecodeException('test-provider', new RuntimeException('underlying'))
    );

    expect($wrapped->reason)->toBe('stream_decode');
});

it('classifies PrismServerException as reason=server', function () {
    $wrapped = LlmException::fromPrism(new PrismServerException('upstream is down'));

    expect($wrapped->reason)->toBe('server');
});

it('classifies an exception whose message looks like an auth failure as reason=auth', function () {
    $wrapped = LlmException::fromPrism(new RuntimeException('Unauthorized: invalid API key'));

    expect($wrapped->reason)->toBe('auth');
});

it('falls back to reason=unknown for unrecognized exceptions', function () {
    $wrapped = LlmException::fromPrism(new RuntimeException('something exploded'));

    expect($wrapped->reason)->toBe('unknown');
});

it('falls back to reason=unknown for generic PrismException', function () {
    $wrapped = LlmException::fromPrism(new class('boom') extends PrismException {});

    expect($wrapped->reason)->toBe('unknown');
});

it('returns the same instance when given an LlmException (no double wrap)', function () {
    $original = new LlmException('boom', 'auth');

    expect(LlmException::fromPrism($original))->toBe($original);
});

it('preserves the original exception as previous', function () {
    $original = new PrismServerException('boom');
    $wrapped = LlmException::fromPrism($original);

    expect($wrapped->getPrevious())->toBe($original)
        ->and($wrapped->getMessage())->toBe('boom');
});
