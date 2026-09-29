<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Syriable\UserPresence\Data\Location;

final readonly class LocationChanged
{
    use Dispatchable;

    public function __construct(
        public Model $user,
        public ?Location $location,
    ) {}
}
