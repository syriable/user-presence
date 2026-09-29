<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Syriable\UserPresence\Concerns\HasUserPresence;

/**
 * A second, unrelated user model authenticated through its own guard.
 */
final class Admin extends Authenticatable
{
    use HasUserPresence;

    protected $table = 'admins';

    protected $guarded = [];
}
