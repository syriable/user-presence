<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;
use Syriable\UserPresence\Contracts\LocationProvider;
use Syriable\UserPresence\Data\Location;

final readonly class StaticLocationProvider implements LocationProvider
{
    public function __construct(
        private ?Location $location = null,
        private bool $fails = false,
    ) {}

    public function locate(Model $user, Request $request): ?Location
    {
        if ($this->fails) {
            throw new RuntimeException('Provider unavailable.');
        }

        return $this->location;
    }
}
