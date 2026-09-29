<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Enums;

enum PresenceStatus: string
{
    case Online = 'online';
    case Offline = 'offline';

    public function isOnline(): bool
    {
        return $this === self::Online;
    }
}
