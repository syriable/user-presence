<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Syriable\UserPresence\Data\TimeDifference;

it('formats signed offsets', function (int $seconds, string $expected): void {
    $difference = new TimeDifference('UTC', 'UTC', $seconds, CarbonImmutable::now());

    expect($difference->format())->toBe($expected);
})->with([
    [0, '+00:00'],
    [3600, '+01:00'],
    [-3600, '-01:00'],
    [19800, '+05:30'],
    [-34200, '-09:30'],
    [49500, '+13:45'],
]);

it('describes the direction of the difference', function (): void {
    $ahead = new TimeDifference('UTC', 'Asia/Tokyo', 32400, CarbonImmutable::now());
    $behind = new TimeDifference('UTC', 'America/New_York', -14400, CarbonImmutable::now());

    expect($ahead->isAhead())->toBeTrue()
        ->and($ahead->isBehind())->toBeFalse()
        ->and($ahead->inHours())->toBe(9.0)
        ->and($behind->isBehind())->toBeTrue()
        ->and($behind->inMinutes())->toBe(-240)
        ->and($behind->toArray())->toMatchArray(['formatted' => '-04:00', 'seconds' => -14400]);
});
