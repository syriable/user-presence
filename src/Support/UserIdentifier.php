<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Builds a stable "morph-class:key" identifier for cache keys.
 *
 * @internal
 */
final class UserIdentifier
{
    public static function of(Model $user): string
    {
        $key = $user->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(sprintf(
                'Presence can only be tracked for persisted models with an integer or string key, [%s] given.',
                $user::class,
            ));
        }

        return $user->getMorphClass().':'.$key;
    }
}
