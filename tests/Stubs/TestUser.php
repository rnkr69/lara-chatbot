<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent User for the orchestrator tests (`ChatService`,
 * E08). Conversations use it through their `morphTo('user')`. It has no
 * real table: `getMorphClass()` returns the FQCN and `getKey()` reads the
 * `id` attribute each test sets via the constructor. We bypass the real
 * load with `Conversation::setRelation('user', $user)`.
 */
class TestUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $guarded = [];

    public $timestamps = false;
}
