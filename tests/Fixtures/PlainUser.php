<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A user model that does not use the HasUserPresence trait.
 */
final class PlainUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
