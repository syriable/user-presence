<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\LocaleResolver;

final readonly class StaticLocaleResolver implements LocaleResolver
{
    public function __construct(private ?string $locale = 'fr') {}

    public function resolve(Model $user): ?string
    {
        return $this->locale;
    }
}
