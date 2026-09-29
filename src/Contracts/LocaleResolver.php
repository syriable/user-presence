<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves an interface locale for a user without a stored locale.
 *
 * Return a language tag such as "ar" or "zh-CN", or null to let the next
 * resolver (or the configured default) decide. Unsupported values are ignored.
 */
interface LocaleResolver
{
    public function resolve(Model $user): ?string;
}
