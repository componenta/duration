<?php

declare(strict_types=1);

namespace Componenta\Stdlib\Tests;

use Componenta\Stdlib\Duration;

it('parses and normalizes fixed ISO 8601 durations', function (): void {
    $duration = Duration::fromISO8601('PT90S');

    expect($duration->toISO8601())->toBe('PT1M30S')
        ->and($duration->toSeconds())->toBe(90)
        ->and($duration->toArray())->toBe([
            'years' => 0,
            'months' => 0,
            'days' => 0,
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 90,
        ]);
});

it('keeps calendar components separate from fixed seconds', function (): void {
    $duration = Duration::ofMonths(1);

    expect($duration->hasCalendarComponents())->toBeTrue()
        ->and(fn () => $duration->toSeconds())->toThrow(\RuntimeException::class);
});

it('calculates elapsed duration between timestamps', function (): void {
    $start = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
    $end = new \DateTimeImmutable('2026-01-01 01:01:10 UTC');

    expect(Duration::betweenElapsed($start, $end)->toISO8601())->toBe('PT1H1M10S');
});
