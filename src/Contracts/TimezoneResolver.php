<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a timezone for a user without a stored timezone.
 *
 * Return an IANA identifier such as "Europe/Berlin", or null to let the next
 * resolver (or the configured default) decide. Invalid values are ignored.
 */
interface TimezoneResolver
{
    public function resolve(Model $user): ?string;
}
