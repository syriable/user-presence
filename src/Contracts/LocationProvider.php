<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Syriable\UserPresence\Data\Location;

/**
 * Detects a coarse (city / country level) location for a request.
 *
 * Return null when nothing can be determined. Exceptions are reported and
 * treated as "unknown", and malformed fields are discarded, so a failing
 * provider never breaks a login.
 */
interface LocationProvider
{
    public function locate(Model $user, Request $request): ?Location;
}
