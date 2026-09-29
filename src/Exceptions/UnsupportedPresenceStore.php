<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Exceptions;

use LogicException;

final class UnsupportedPresenceStore extends LogicException
{
    public static function notQueryable(string $store): self
    {
        return new self(
            "The [{$store}] presence store cannot be queried with Eloquent. Use the \"database\" store to query online users.",
        );
    }

    public static function invalid(string $store, string $contract): self
    {
        return new self("The [{$store}] presence store must implement [{$contract}].");
    }
}
