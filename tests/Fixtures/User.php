<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Syriable\UserPresence\Concerns\HasUserPresence;

final class User extends Authenticatable
{
    use HasUserPresence;
    use SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];
}
