<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * User Eloquent mínimo para los tests del orquestador (`ChatService`,
 * E08). Lo usan las conversaciones por su `morphTo('user')`. No tiene
 * tabla real: `getMorphClass()` devuelve el FQCN y `getKey()` lee el
 * attribute `id` que cada test setea por constructor. Bypassamos la
 * carga real con `Conversation::setRelation('user', $user)`.
 */
class TestUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $guarded = [];

    public $timestamps = false;
}
