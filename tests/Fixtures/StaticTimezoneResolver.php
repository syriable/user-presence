<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\TimezoneResolver;

final readonly class StaticTimezoneResolver implements TimezoneResolver
{
    public function __construct(private ?string $timezone = 'America/New_York') {}

    public function resolve(Model $user): ?string
    {
        return $this->timezone;
    }
}
