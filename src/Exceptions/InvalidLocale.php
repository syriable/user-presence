<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Exceptions;

use InvalidArgumentException;

final class InvalidLocale extends InvalidArgumentException
{
    public static function unsupported(string $locale): self
    {
        return new self("The locale [{$locale}] is not a supported interface locale.");
    }

    public static function malformed(string $locale): self
    {
        return new self("The value [{$locale}] is not a valid language tag.");
    }
}
