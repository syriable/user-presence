<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Enums;

/**
 * Where a stored locale, timezone or location came from.
 */
enum ContextSource: string
{
    /** Explicitly chosen or entered by the user (or the application on their behalf). */
    case User = 'user';

    /** Reported by the user's browser, for example Accept-Language or Intl timezone. */
    case Browser = 'browser';

    /** Inferred by a location provider, for example from CDN headers or an IP database. */
    case Provider = 'provider';

    /**
     * Automatically inferred values may be replaced by later detections and
     * must never be presented as something the user stated.
     */
    public function isInferred(): bool
    {
        return $this !== self::User;
    }
}
