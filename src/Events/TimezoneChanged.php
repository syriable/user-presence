<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Syriable\UserPresence\Enums\ContextSource;

final readonly class TimezoneChanged
{
    use Dispatchable;

    public function __construct(
        public Model $user,
        public ?string $timezone,
        public ?string $previousTimezone,
        public ?ContextSource $source,
    ) {}
}
