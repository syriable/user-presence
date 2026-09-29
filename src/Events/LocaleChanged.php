<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Syriable\UserPresence\Enums\ContextSource;

final readonly class LocaleChanged
{
    use Dispatchable;

    public function __construct(
        public Model $user,
        public ?string $locale,
        public ?string $previousLocale,
        public ?ContextSource $source,
    ) {}
}
