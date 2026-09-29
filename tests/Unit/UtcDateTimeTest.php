<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Syriable\UserPresence\Casts\UtcDateTime;
use Syriable\UserPresence\Models\PresenceRecord;

it('stores any datetime as UTC', function (): void {
    $cast = new UtcDateTime;

    expect($cast->set(new PresenceRecord, 'at', CarbonImmutable::parse('2026-06-01 14:00:00', 'Europe/Berlin'), []))
        ->toBe('2026-06-01 12:00:00')
        ->and($cast->set(new PresenceRecord, 'at', null, []))->toBeNull();
});

it('reads stored values as UTC', function (): void {
    $value = (new UtcDateTime)->get(new PresenceRecord, 'at', '2026-06-01 12:00:00', []);

    expect($value?->toIso8601String())->toBe('2026-06-01T12:00:00+00:00')
        ->and((new UtcDateTime)->get(new PresenceRecord, 'at', null, []))->toBeNull();
});
