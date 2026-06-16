# Componenta Duration

Immutable ISO 8601 duration value object with calendar-aware and fixed-duration operations.

Use it when application code needs a typed duration instead of passing raw seconds or raw ISO strings.

## Installation

```bash
composer require componenta/duration
```

## Related Packages

This package is standalone.

| Package | Why it may be used nearby |
|---|---|
| `componenta/clock` | Provides reference dates for calendar-aware comparisons. |
| `componenta/config` | Can store ISO 8601 durations that are converted to `Duration`. |
| `componenta/validation` | Can validate user strings before creating the value object. |

## Usage

```php
use Componenta\Stdlib\Duration;

$duration = Duration::fromISO8601('PT90S');

$duration->toISO8601(); // "PT1M30S"
$duration->toSeconds(); // 90
$duration->humanize();  // "1 minute 30 seconds"
```

Factories are available for common units:

```php
Duration::ofDays(2);
Duration::ofHours(6);
Duration::fromSeconds(3600);
Duration::between($start, $end);
```

## Calendar Components

Years and months are calendar components. They are not converted to seconds without a reference date because their exact length depends on the calendar.

```php
$duration = Duration::ofMonths(1);

$duration->hasCalendarComponents(); // true
$duration->isFixed();               // false
$duration->toSeconds();             // throws RuntimeException
$duration->toSecondsFrom(new DateTimeImmutable('2026-01-01'));
```

Weeks are expanded to days when parsing ISO 8601 because the value object stores days, not a separate week component.

## Arithmetic

`Duration` supports immutable arithmetic:

- `add`
- `subtract`
- `subtractClamped`
- `multiply`
- `divideBy`
- `sum`
- `min`
- `max`

Use `compareTo()` only for comparable durations. Calendar-aware comparison is available through `compareToFrom()` with a reference date.

## Conversion

The object can convert to:

- ISO 8601 string
- `DateInterval`
- dense array through `toArray()`
- sparse array through `toSparseArray()`
- custom string through `format()`
- JSON through `jsonSerialize()`

`applyTo()` adds the duration to a `DateTimeInterface` and returns a `DateTimeImmutable`.

## Supported Format

The parser supports integer ISO 8601 components: `P[n]Y[n]M[n]W[n]DT[n]H[n]M[n]S`.

Fractional components such as `PT1.5S` are rejected.
