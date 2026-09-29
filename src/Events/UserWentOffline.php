<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class UserWentOffline
{
    use Dispatchable;

    public function __construct(public Model $user) {}
}
