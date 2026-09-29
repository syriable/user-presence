<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Exceptions;

use InvalidArgumentException;

final class InvalidLocation extends InvalidArgumentException
{
    public static function countryCode(string $countryCode): self
    {
        return new self("The country code [{$countryCode}] is not an ISO 3166-1 alpha-2 code.");
    }
}
