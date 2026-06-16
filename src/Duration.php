<?php

declare(strict_types=1);

namespace Componenta\Stdlib;

/**
 * Immutable representation of a time duration (ISO 8601 period).
 *
 * Stores calendar components (years, months) separately from fixed-time
 * components (days, hours, minutes, seconds). This prevents silent
 * approximation: P1M is always "one month", not "30.44 days".
 *
 * Operations that require a reference date (e.g. converting months to seconds)
 * are only available through methods that accept a DateTimeInterface.
 *
 * Supported subset of ISO 8601 (integer components only):
 *   P[n]Y[n]M[n]W[n]DT[n]H[n]M[n]S
 * Fractional designators (PT1.5S, P0.5Y) are not supported.
 *
 * String round-trip note: toISO8601() normalises weeks to days (P2W -> P14D),
 * so the string form is not always identical after a round-trip. Component
 * equality is always preserved: fromISO8601(x)->equals(fromISO8601(x)) = true.
 *
 * DST semantics: methods that compute elapsed seconds (toSecondsFrom(),
 * betweenElapsed()) always work in Unix timestamps and are therefore
 * DST-unaware. Adding PT24H across a DST boundary may yield 23 or 25 wall-
 * clock hours. This matches PHP's DateTimeImmutable::add() behaviour.
 */
final class Duration implements \Stringable, \JsonSerializable
{
    private const SECONDS_IN_MINUTE   = 60;
    private const SECONDS_IN_HOUR     = 3600;
    private const SECONDS_IN_DAY      = 86400;
    private const SECONDS_IN_WEEK     = 604800;

    /**
     * Upper bound for the weeks-to-days multiplication in ofWeeks().
     * Weeks are multiplied by 7 before reaching the constructor, so the
     * overflow must be caught before that multiplication happens.
     */
    private const MAX_SAFE_WEEKS = PHP_INT_MAX / 7;

    /**
     * @param int $years   Calendar years (variable length; cannot convert to
     *                     seconds without a reference date)
     * @param int $months  Calendar months (same caveat as years)
     * @param int $days    Fixed days
     * @param int $hours   Fixed hours
     * @param int $minutes Fixed minutes
     * @param int $seconds Fixed seconds
     *
     * @throws \InvalidArgumentException If any component is negative or would
     *                                   overflow PHP_INT_MAX when converted to seconds
     */
    public function __construct(
        public readonly int $years   = 0,
        public readonly int $months  = 0,
        public readonly int $days    = 0,
        public readonly int $hours   = 0,
        public readonly int $minutes = 0,
        public readonly int $seconds = 0,
    ) {
        foreach (
            ['years' => $years, 'months' => $months, 'days' => $days,
             'hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds]
            as $name => $value
        ) {
            if ($value < 0) {
                throw new \InvalidArgumentException(
                    "Duration component \"$name\" must be non-negative, got $value."
                );
            }
        }

        // Guard the collective fixed-seconds sum against overflow.
        // Each multiplication is checked via safeMul() before being accumulated,
        // because $days * 86400 can overflow PHP_INT_MAX before safeAdd() runs.
        $acc = 0;
        $acc = self::safeAdd($acc, self::safeMul($days,    self::SECONDS_IN_DAY,    'days'),    'accumulated');
        $acc = self::safeAdd($acc, self::safeMul($hours,   self::SECONDS_IN_HOUR,   'hours'),   'accumulated');
        $acc = self::safeAdd($acc, self::safeMul($minutes, self::SECONDS_IN_MINUTE, 'minutes'), 'accumulated');
        $acc = self::safeAdd($acc, $seconds,                                                     'accumulated');
    }


    public static function of(
        int $years   = 0,
        int $months  = 0,
        int $days    = 0,
        int $hours   = 0,
        int $minutes = 0,
        int $seconds = 0,
    ): self {
        return new self($years, $months, $days, $hours, $minutes, $seconds);
    }

    public static function ofYears(int $years): self    { return new self(years: $years); }
    public static function ofMonths(int $months): self  { return new self(months: $months); }
    public static function ofWeeks(int $weeks): self
    {
        if ($weeks < 0) {
            throw new \InvalidArgumentException(
                "ofWeeks() requires a non-negative value, got $weeks."
            );
        }
        if ($weeks > self::MAX_SAFE_WEEKS) {
            throw new \OverflowException(
                "ofWeeks($weeks): multiplying by 7 would overflow PHP_INT_MAX before reaching the constructor."
            );
        }
        return new self(days: $weeks * 7);
    }
    public static function ofDays(int $days): self      { return new self(days: $days); }
    public static function ofHours(int $hours): self    { return new self(hours: $hours); }
    public static function ofMinutes(int $minutes): self { return new self(minutes: $minutes); }
    public static function ofSeconds(int $seconds): self { return new self(seconds: $seconds); }
    public static function zero(): self                 { return new self(); }

    /**
     * Creates a Duration from a total number of seconds, decomposing into
     * days, hours, minutes, and seconds. Calendar components are not produced.
     *
     *   Duration::fromSeconds(3670) -> PT1H1M10S (after normalization)
     */
    public static function fromSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                "fromSeconds() requires a non-negative value, got $seconds."
            );
        }
        return self::ofSeconds($seconds)->normalized();
    }

    /**
     * Parses a supported subset of ISO 8601 duration strings.
     *
     * Weeks are expanded to days (1W = 7D) since the class has no separate
     * weeks component. This means P2W and P14D are equal under equals(), but
     * toISO8601() always emits P14D.
     *
     * Fractional designators (PT1.5S) are not supported and will throw.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromISO8601(string $value): self
    {
        $m = self::parseComponents($value);

        if ($m === null) {
            throw new \InvalidArgumentException(
                "Invalid or unsupported ISO 8601 duration: \"$value\". "
                . "Fractional designators (e.g. PT1.5S) are not supported."
            );
        }

        return new self(
            years:   $m['years'],
            months:  $m['months'],
            days:    $m['days'],
            hours:   $m['hours'],
            minutes: $m['minutes'],
            seconds: $m['seconds'],
        );
    }

    /**
     * Creates a Duration from a DateInterval.
     *
     * DateInterval::$f (microseconds) is ignored.
     *
     * This method uses $interval->d (the days component within the current month),
     * not $interval->days (total elapsed days since epoch). This is deliberate:
     * it preserves calendar semantics so that a diff of "1 month, 2 days" is
     * stored as months=1, days=2, not flattened into 32 days. If you need the
     * total-days representation, use betweenElapsed() instead.
     *
     * @throws \InvalidArgumentException If the interval is inverted (negative)
     */
    public static function fromDateInterval(\DateInterval $interval): self
    {
        if ($interval->invert === 1) {
            throw new \InvalidArgumentException(
                'Cannot create a Duration from an inverted (negative) DateInterval.'
            );
        }

        return new self(
            years:   $interval->y,
            months:  $interval->m,
            days:    $interval->d,
            hours:   $interval->h,
            minutes: $interval->i,
            seconds: $interval->s,
        );
    }

    /**
     * Creates a calendar Duration representing the difference between two dates
     * using PHP's DateTimeImmutable::diff(). The result contains years, months,
     * days, hours, minutes, and seconds as returned by PHP's calendar diff.
     *
     * Note: diff() applies calendar arithmetic, so the result can be surprising
     * at month boundaries. 2025-01-31 -> 2025-03-01 becomes P1M1D, not P29D.
     * Use betweenElapsed() if you need an exact elapsed-second count.
     *
     * The direction (start -> end) does not matter; the result is always positive.
     */
    public static function between(\DateTimeInterface $start, \DateTimeInterface $end): self
    {
        $a = \DateTimeImmutable::createFromInterface($start);
        $b = \DateTimeImmutable::createFromInterface($end);
        return self::fromDateInterval($a->diff($b, absolute: true));
    }

    /**
     * Creates a fixed Duration from the elapsed seconds between two timestamps.
     *
     * Unlike between(), this method does not produce calendar components (years,
     * months). The result is always an exact number of seconds decomposed into
     * days/hours/minutes/seconds.
     *
     * DST note: computed via Unix timestamps, so a DST transition in the range
     * is counted as elapsed seconds, not as wall-clock hours. Adding PT24H
     * across a DST boundary may yield 23 or 25 wall-clock hours.
     */
    public static function betweenElapsed(\DateTimeInterface $start, \DateTimeInterface $end): self
    {
        $elapsed = abs($end->getTimestamp() - $start->getTimestamp());
        return self::fromSeconds($elapsed);
    }


    /**
     * Returns true if the string is a parseable ISO 8601 duration (integer
     * components only; fractional designators are not supported).
     *
     * Accepts: PnYnMnWnDTnHnMnS, PnW, PT0S, etc.
     * Rejects: bare 'P', 'PT', fractional values, garbage.
     *
     * Week handling is intentionally permissive: P1W2D and P1WT2H are accepted
     * and weeks are expanded to days (1W = 7D). Strict ISO 8601 forbids mixing
     * weeks with other date designators, but that restriction is not enforced
     * here for ergonomic reasons.
     */
    public static function validate(string $value): bool
    {
        return self::parseComponents($value) !== null;
    }


    public function isZero(): bool
    {
        return $this->years === 0
            && $this->months === 0
            && $this->days === 0
            && $this->hours === 0
            && $this->minutes === 0
            && $this->seconds === 0;
    }

    /**
     * Returns true if this duration contains calendar components (years or months).
     *
     * Calendar-component durations cannot be converted to a fixed number of
     * seconds without a reference date. Methods such as toSeconds() will throw;
     * use toSecondsFrom($base) instead.
     */
    public function hasCalendarComponents(): bool
    {
        return $this->years !== 0 || $this->months !== 0;
    }

    /**
     * Returns true if this duration contains only fixed components (days, hours,
     * minutes, seconds), meaning no years or months.
     *
     * This is the inverse of hasCalendarComponents() and exists to avoid the
     * double-negative `!hasCalendarComponents()` at call sites:
     *
     *   if ($duration->isFixed()) { ... }  // clear
     *   if (!$duration->hasCalendarComponents()) { ... }  // awkward
     *
     * Fixed durations can be converted to seconds without a reference date.
     */
    public function isFixed(): bool
    {
        return !$this->hasCalendarComponents();
    }
    // Fixed components only; no reference date is needed.

    /**
     * Total fixed seconds (days + hours + minutes + seconds components only).
     *
     * @throws \RuntimeException If the duration has calendar components.
     *                           Use toSecondsFrom() instead.
     */
    public function toSeconds(): int
    {
        $this->assertNoCalendarComponents(__METHOD__);
        return $this->fixedSeconds();
    }

    /** @throws \RuntimeException If calendar components are present. */
    public function toMinutes(): int
    {
        $this->assertNoCalendarComponents(__METHOD__);
        return intdiv($this->fixedSeconds(), self::SECONDS_IN_MINUTE);
    }

    /** @throws \RuntimeException If calendar components are present. */
    public function toHours(): int
    {
        $this->assertNoCalendarComponents(__METHOD__);
        return intdiv($this->fixedSeconds(), self::SECONDS_IN_HOUR);
    }

    /** @throws \RuntimeException If calendar components are present. */
    public function toDays(): int
    {
        $this->assertNoCalendarComponents(__METHOD__);
        return intdiv($this->fixedSeconds(), self::SECONDS_IN_DAY);
    }

    /** @throws \RuntimeException If calendar components are present. */
    public function toWeeks(): int
    {
        $this->assertNoCalendarComponents(__METHOD__);
        return intdiv($this->fixedSeconds(), self::SECONDS_IN_WEEK);
    }
    // Calendar-aware conversion requires a reference date.

    /**
     * Total seconds when this duration is applied starting from $base.
     *
     * DST semantics: computed via Unix timestamps. A DST transition within the
     * duration is counted as elapsed seconds, not as wall-clock hours. This
     * matches PHP's DateTimeImmutable::add() behaviour.
     *
     * Note: may return a negative value in rare cases involving historical
     * timezone offset changes (e.g. a timezone that jumped forward by more
     * than the duration). This is correct and intentional: it reflects actual
     * elapsed time from the system's perspective. Using abs() would silently
     * discard this information.
     */
    public function toSecondsFrom(\DateTimeInterface $base): int
    {
        return $this->applyTo($base)->getTimestamp() - $base->getTimestamp();
    }

    /**
     * Calendar days elapsed when this duration is applied from $base.
     *
     * Uses PHP's DateTimeImmutable::diff() so the result reflects calendar
     * days, not elapsed 86 400-second intervals. P1D always returns 1 even
     * across a DST boundary.
     *
     * This is intentionally different from intdiv(toSecondsFrom($base), 86400),
     * which would return 0 for a 23-hour spring-forward day.
     */
    public function toDaysFrom(\DateTimeInterface $base): int
    {
        $end  = $this->applyTo($base);
        $diff = \DateTimeImmutable::createFromInterface($base)->diff($end);
        return (int) $diff->format('%a');
    }

    /**
     * Applies this duration to a date, returning the resulting DateTimeImmutable.
     *
     * DST semantics: delegates to DateTimeImmutable::add(), which performs
     * wall-clock arithmetic. PT24H across a DST boundary may arrive at an
     * unexpected wall-clock time.
     */
    public function applyTo(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->add($this->toDateInterval());
    }


    /**
     * Canonicalises fixed-time overflow by carrying into larger units.
     *
     * All fixed components (days, hours, minutes, seconds) are summed into a
     * total-seconds value, then decomposed cleanly. Calendar components (years,
     * months) are passed through unchanged.
     *
     * Examples:
     *   PT90S    -> PT1M30S
     *   PT25H    -> P1DT1H
     *   P1DT25H  -> P2DT1H
     *   P1DT24H  -> P2D
     *
     * Note: days are NOT rolled up into months or years, as their length is
     * date-dependent and cannot be normalised without a reference date.
     *
     * WARNING: normalization changes component identity:
     *   $a = Duration::ofSeconds(60);
     *   $a->normalized()->equals($a)       // false: components differ (minutes:1 vs seconds:60)
     *   $a->normalized()->equivalentTo($a) // true: same total seconds
     * Use equivalentTo() when you need semantic comparison across representations.
     */
    public function normalized(): self
    {
        $total = $this->fixedSeconds();

        $d = intdiv($total, self::SECONDS_IN_DAY);
        $total -= $d * self::SECONDS_IN_DAY;

        $h = intdiv($total, self::SECONDS_IN_HOUR);
        $total -= $h * self::SECONDS_IN_HOUR;

        $m = intdiv($total, self::SECONDS_IN_MINUTE);
        $s = $total - $m * self::SECONDS_IN_MINUTE;

        return new self(
            years:   $this->years,
            months:  $this->months,
            days:    $d,
            hours:   $h,
            minutes: $m,
            seconds: $s,
        );
    }


    /**
     * @throws \OverflowException If any resulting component would exceed PHP_INT_MAX.
     */
    public function add(self $other): self
    {
        return new self(
            self::safeAdd($this->years,   $other->years,   'years'),
            self::safeAdd($this->months,  $other->months,  'months'),
            self::safeAdd($this->days,    $other->days,    'days'),
            self::safeAdd($this->hours,   $other->hours,   'hours'),
            self::safeAdd($this->minutes, $other->minutes, 'minutes'),
            self::safeAdd($this->seconds, $other->seconds, 'seconds'),
        );
    }

    /**
     * Subtracts $other from this duration component by component.
     *
     * Each component is subtracted in isolation; no cross-component borrowing
     * occurs. Use this when you are explicitly working with calendar components
     * (e.g. P2Y3M minus P1Y1M = P1Y2M) or when you know all components of
     * $other are <= the corresponding components of $this.
     *
     *   Duration::of(years: 2, months: 3)->subtractComponents(Duration::of(years: 1, months: 1))
     *   // -> P1Y2M
     *
     *   Duration::ofMinutes(1)->subtractComponents(Duration::ofSeconds(30))
     *   // throws: "seconds" would be -30; use subtract() instead
     *
     * Throws if any resulting component would be negative.
     * Use subtractClamped() if you explicitly want each component floored at 0.
     *
     * @throws \InvalidArgumentException If any component would go negative.
     */
    public function subtractComponents(self $other): self
    {
        $result = [
            'years'   => $this->years   - $other->years,
            'months'  => $this->months  - $other->months,
            'days'    => $this->days    - $other->days,
            'hours'   => $this->hours   - $other->hours,
            'minutes' => $this->minutes - $other->minutes,
            'seconds' => $this->seconds - $other->seconds,
        ];

        foreach ($result as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(
                    "subtractComponents(): component \"$name\" would become negative ($value). "
                    . "Use subtract() for elapsed-time subtraction with cross-component borrowing, "
                    . "or subtractClamped() to clamp each component to zero."
                );
            }
        }

        return new self(...$result);
    }

    /**
     * Subtracts $other from this duration using total elapsed seconds.
     *
     * Converts both durations to their total seconds, subtracts, then
     * decomposes the result. Cross-component borrowing works naturally:
     *
     *   Duration::ofMinutes(1)->subtract(Duration::ofSeconds(30))
     *   // -> PT30S  (60s - 30s = 30s)
     *
     *   Duration::ofHours(2)->subtract(Duration::ofMinutes(30))
     *   // -> PT1H30M  (7200s - 1800s = 5400s)
     *
     * Throws if $other is longer than $this (result would be negative).
     * Use subtractClamped() if you need a zero floor instead.
     * Use subtractComponents() if you need component-by-component subtraction
     * without borrowing (e.g. for calendar durations with years/months).
     *
     * @throws \RuntimeException         If either duration has calendar components.
     * @throws \InvalidArgumentException If $other is longer than $this.
     */
    public function subtract(self $other): self
    {
        $this->assertNoCalendarComponents(__METHOD__);
        $other->assertNoCalendarComponents(__METHOD__);

        $diff = $this->fixedSeconds() - $other->fixedSeconds();

        if ($diff < 0) {
            throw new \InvalidArgumentException(
                "subtract(): result would be negative ($diff seconds). "
                . '$other is longer than $this.'
            );
        }

        return self::fromSeconds($diff);
    }

    /**
     * Subtracts $other from this duration, clamping each component to zero.
     *
     * WARNING: no cross-component borrowing occurs. Each component is
     * clamped independently, which can produce surprising results:
     *
     *   Duration::of(months: 1, days: 10)
     *       ->subtractClamped(Duration::ofDays(20))
     *   // -> P1M  (days clamped to 0; the month is untouched)
     *   // NOT P21D or anything involving elapsed-time semantics
     *
     *   Duration::ofMonths(1)->subtractClamped(Duration::ofMonths(2))
     *   // -> P0M  (clamped, no exception)
     *
     * Use subtract() for elapsed-time subtraction (fixed durations only).
     * Use subtractComponents() when you need component-wise subtraction
     * that throws on underflow rather than clamping.
     */
    public function subtractClamped(self $other): self
    {
        return new self(
            max(0, $this->years   - $other->years),
            max(0, $this->months  - $other->months),
            max(0, $this->days    - $other->days),
            max(0, $this->hours   - $other->hours),
            max(0, $this->minutes - $other->minutes),
            max(0, $this->seconds - $other->seconds),
        );
    }

    /**
     * Multiplies every component by $factor.
     *
     * A factor of 0 is allowed and returns Duration::zero().
     * Negative factors are rejected because Duration components must be
     * non-negative.
     *
     * @throws \InvalidArgumentException If $factor is negative.
     * @throws \OverflowException If any resulting component would overflow.
     */
    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException(
                "Factor must be a non-negative integer, got $factor."
            );
        }

        if ($factor === 0) {
            return self::zero();
        }

        return new self(
            self::safeMul($this->years,   $factor, 'years'),
            self::safeMul($this->months,  $factor, 'months'),
            self::safeMul($this->days,    $factor, 'days'),
            self::safeMul($this->hours,   $factor, 'hours'),
            self::safeMul($this->minutes, $factor, 'minutes'),
            self::safeMul($this->seconds, $factor, 'seconds'),
        );
    }

    /**
     * Integer-divides (floor) every component by $divisor independently.
     *
     * Each component is divided in isolation; no cross-component remainder
     * propagation. Use this when you need to scale calendar components
     * (years, months) or when component-wise division is explicitly intended:
     *
     *   Duration::of(years: 2, months: 6)->divideByComponents(2)
     *   // -> P1Y3M  (2/2=1 year, 6/2=3 months)
     *
     *   Duration::of(minutes: 1, seconds: 30)->divideByComponents(2)
     *   // -> PT15S  (1/2=0 min, 30/2=15 sec; use divideBy() for PT45S)
     *
     * @throws \InvalidArgumentException If $divisor is not a positive integer.
     */
    public function divideByComponents(int $divisor): self
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException(
                "Divisor must be a positive integer, got $divisor."
            );
        }

        return new self(
            intdiv($this->years,   $divisor),
            intdiv($this->months,  $divisor),
            intdiv($this->days,    $divisor),
            intdiv($this->hours,   $divisor),
            intdiv($this->minutes, $divisor),
            intdiv($this->seconds, $divisor),
        );
    }

    /**
     * Divides the total elapsed seconds by $divisor and returns a normalised
     * Duration. The remainder is preserved across components (no per-component loss):
     *
     *   Duration::of(minutes: 1, seconds: 30)->divideBy(2)
     *   // -> PT45S  (90 total seconds / 2 = 45 seconds)
     *
     *   Duration::ofHours(3)->divideBy(2)
     *   // -> PT1H30M  (10800s / 2 = 5400s)
     *
     * Use divideByComponents() if you need each component divided independently,
     * or if the duration contains calendar components (years, months).
     *
     * @throws \RuntimeException If the duration has calendar components.
     * @throws \InvalidArgumentException If $divisor is not a positive integer.
     */
    public function divideBy(int $divisor): self
    {
        $this->assertNoCalendarComponents(__METHOD__);

        if ($divisor <= 0) {
            throw new \InvalidArgumentException(
                "Divisor must be a positive integer, got $divisor."
            );
        }

        return self::fromSeconds(intdiv($this->fixedSeconds(), $divisor));
    }


    /**
     * Returns true if both durations can be directly compared via compareTo().
     *
     * compareTo() throws when either duration has calendar components (years or
     * months), because their length in seconds is date-dependent. Use this
     * predicate to avoid exception-as-control-flow:
     *
     *   if ($a->isComparable($b)) {
     *       $result = $a->compareTo($b);
     *   }
     *
     * Durations with only fixed components (days, hours, minutes, seconds)
     * are always comparable to each other.
     */
    public function isComparable(self $other): bool
    {
        return !$this->hasCalendarComponents() && !$other->hasCalendarComponents();
    }

    /**
     * Compares two fixed durations (no calendar components).
     *
     * @throws \RuntimeException If either duration has calendar components.
     *                           Use isComparable() to check first, or
     *                           compareToFrom() to compare with a reference date.
     * @return int  -1 | 0 | 1
     */
    public function compareTo(self $other): int
    {
        $this->assertNoCalendarComponents(__METHOD__);
        $other->assertNoCalendarComponents(__METHOD__);
        return $this->fixedSeconds() <=> $other->fixedSeconds();
    }

    /**
     * Compares two durations relative to $base. Safe with calendar components.
     *
     * WARNING: ordering is not stable and may violate strict weak ordering:
     *   - P1M vs P30D -> P1M wins in January, ties in April, loses in February.
     *   - A DST transition within one duration but not the other can cause
     *     the comparator to be non-transitive: A > B and B > C does not
     *     guarantee A > C when base dates differ.
     * Do not use this in usort() or any context requiring a stable, transitive
     * ordering. Use compareTo() with fixed durations instead.
     *
     * @return int  -1 | 0 | 1
     */
    public function compareToFrom(\DateTimeInterface $base, self $other): int
    {
        return $this->toSecondsFrom($base) <=> $other->toSecondsFrom($base);
    }

    /**
     * Structural (component-wise) equality.
     *
     * PT60S and PT1M are NOT equal under this method because they have
     * different component values. Use equivalentTo() to compare by total
     * fixed seconds.
     */
    public function equals(self $other): bool
    {
        return $this->years   === $other->years
            && $this->months  === $other->months
            && $this->days    === $other->days
            && $this->hours   === $other->hours
            && $this->minutes === $other->minutes
            && $this->seconds === $other->seconds;
    }

    /**
     * Semantic equality: two fixed durations are equivalent if they represent
     * the same total number of seconds, regardless of how the components are
     * distributed.
     *
     *   Duration::ofSeconds(60)->equivalentTo(Duration::ofMinutes(1)) // true
     *   Duration::ofDays(1)->equivalentTo(Duration::ofHours(24))      // true
     *
     * @throws \RuntimeException If either duration has calendar components,
     *                           because their total seconds are date-dependent.
     */
    public function equivalentTo(self $other): bool
    {
        $this->assertNoCalendarComponents(__METHOD__);
        $other->assertNoCalendarComponents(__METHOD__);
        return $this->fixedSeconds() === $other->fixedSeconds();
    }

    /** @throws \RuntimeException If either duration has calendar components. */
    public function isLongerThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /** @throws \RuntimeException If either duration has calendar components. */
    public function isShorterThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Returns true if this duration falls between $min and $max.
     *
     * @throws \RuntimeException If any of the three durations has calendar components.
     */
    public function isBetween(self $min, self $max, bool $inclusive = true): bool
    {
        $minS = $min->toSeconds();
        $maxS = $max->toSeconds();

        if ($minS > $maxS) {
            throw new \InvalidArgumentException(
                'isBetween(): $min must not be greater than $max. '
                . "Got min={$min->toISO8601()} > max={$max->toISO8601()}."
            );
        }

        $v = $this->fixedSeconds();

        return $inclusive
            ? $v >= $minS && $v <= $maxS
            : $v > $minS  && $v < $maxS;
    }


    /**
     * Returns the shorter of two fixed durations.
     *
     * @throws \RuntimeException If either duration has calendar components.
     */
    public static function min(self $a, self $b): self
    {
        return $a->compareTo($b) <= 0 ? $a : $b;
    }

    /**
     * Returns the longer of two fixed durations.
     *
     * @throws \RuntimeException If either duration has calendar components.
     */
    public static function max(self $a, self $b): self
    {
        return $a->compareTo($b) >= 0 ? $a : $b;
    }

    /**
     * Sums an iterable of Duration objects component by component.
     *
     * An empty iterable returns Duration::zero().
     *
     * @param iterable<self> $durations
     * @throws \InvalidArgumentException If any element is not a Duration.
     * @throws \OverflowException If any component sum overflows.
     */
    public static function sum(iterable $durations): self
    {
        $result = self::zero();

        foreach ($durations as $d) {
            if (!$d instanceof self) {
                $type = get_debug_type($d);
                throw new \InvalidArgumentException(
                    "sum() expects an iterable of Duration objects, got $type."
                );
            }
            $result = $result->add($d);
        }

        return $result;
    }


    /**
     * Returns the canonical ISO 8601 duration string.
     *
     * Fixed components (days, hours, minutes, seconds) are always normalised
     * before serialization, so semantically identical durations always produce
     * identical strings regardless of how they were constructed:
     *
     *   Duration::ofSeconds(120)->toISO8601()   // "PT2M"
     *   Duration::fromSeconds(120)->toISO8601() // "PT2M"
     *   Duration::of(hours: 1, minutes: 90)->toISO8601() // "PT2H30M"
     *
     * Calendar components (years, months) are emitted as-is; they cannot be
     * normalised without a reference date.
     *
     * Weeks are not emitted; they are stored as days (P2W -> P14D).
     */
    public function toISO8601(): string
    {
        // Normalise fixed components so that overflow values (e.g. PT90S, PT25H)
        // are always emitted in canonical form. Calendar components pass through.
        $n = $this->normalized();

        if ($n->isZero()) {
            return 'PT0S';
        }

        $s = 'P';
        if ($n->years)   $s .= $n->years   . 'Y';
        if ($n->months)  $s .= $n->months  . 'M';
        if ($n->days)    $s .= $n->days    . 'D';

        $hasTime = $n->hours || $n->minutes || $n->seconds;
        if ($hasTime) {
            $s .= 'T';
            if ($n->hours)   $s .= $n->hours   . 'H';
            if ($n->minutes) $s .= $n->minutes . 'M';
            if ($n->seconds) $s .= $n->seconds . 'S';
        }

        return $s;
    }

    /**
     * Converts to a DateInterval.
     *
     * Built from the ISO 8601 string so years and months are preserved
     * without approximation.
     */
    public function toDateInterval(): \DateInterval
    {
        return new \DateInterval($this->isZero() ? 'PT0S' : $this->toISO8601());
    }

    /**
     * All six components as an associative array, always including zero values.
     *
     * @return array{years:int, months:int, days:int, hours:int, minutes:int, seconds:int}
     */
    public function toArray(): array
    {
        return [
            'years'   => $this->years,
            'months'  => $this->months,
            'days'    => $this->days,
            'hours'   => $this->hours,
            'minutes' => $this->minutes,
            'seconds' => $this->seconds,
        ];
    }

    /**
     * Like toArray(), but omits zero-value components.
     * Returns an empty array when all components are zero.
     *
     * @return array<string, int>
     */
    public function toSparseArray(): array
    {
        return array_filter($this->toArray(), static fn(int $v) => $v !== 0);
    }

    /**
     * Returns a human-readable string of the non-zero components.
     *
     * Examples:
     *   Duration::of(years: 2, months: 3, days: 4)->humanize()
     *   // "2 years, 3 months, 4 days"
     *
     *   Duration::of(hours: 1, minutes: 30)->humanize(short: true)
     *   // "1h 30m"
     *
     * @param bool $short Use abbreviated unit labels (y/mo/d/h/m/s)
     *                    instead of full words.
     */
    public function humanize(bool $short = false): string
    {
        $sparse = $this->toSparseArray();

        if ($sparse === []) {
            return $short ? '0s' : '0 seconds';
        }

        $labels = $short
            ? ['years' => 'y', 'months' => 'mo', 'days' => 'd',
               'hours' => 'h', 'minutes' => 'm', 'seconds' => 's']
            : ['years' => 'year', 'months' => 'month', 'days' => 'day',
               'hours' => 'hour', 'minutes' => 'minute', 'seconds' => 'second'];

        $parts = [];
        foreach ($sparse as $unit => $value) {
            $parts[] = $short
                ? $value . $labels[$unit]
                : $value . ' ' . $labels[$unit] . ($value !== 1 ? 's' : '');
        }

        return $short ? implode(' ', $parts) : implode(', ', $parts);
    }

    /**
     * Formats the duration using printf-like placeholders.
     *
     * Uppercase = zero-padded to 2 digits:
     *   %Y  years    %M  months   %D  days
     *   %H  hours    %I  minutes  %S  seconds
     *
     * Lowercase = raw integer, no padding:
     *   %y  years    %m  months   %d  days
     *   %h  hours    %i  minutes  %s  seconds
     *
     * Special:
     *   %T  total fixed seconds (throws if calendar components are present)
     *
     * Examples:
     *   Duration::of(hours: 1, minutes: 5)->format('%H:%I:%S') -> "01:05:00"
     *   Duration::of(hours: 1, minutes: 5)->format('%h:%i:%s') -> "1:5:0"
     */
    public function format(string $pattern): string
    {
        $pad = static fn(int $v): string => str_pad((string) $v, 2, '0', STR_PAD_LEFT);

        // Protect literal %% before substitution, restore afterwards.
        // Without this, %%H would expand to %<hours-value> instead of %H.
        $pattern = str_replace('%%', "\0", $pattern);

        $replacements = [
            '%Y' => $pad($this->years),   '%y' => (string) $this->years,
            '%M' => $pad($this->months),  '%m' => (string) $this->months,
            '%D' => $pad($this->days),    '%d' => (string) $this->days,
            '%H' => $pad($this->hours),   '%h' => (string) $this->hours,
            '%I' => $pad($this->minutes), '%i' => (string) $this->minutes,
            '%S' => $pad($this->seconds), '%s' => (string) $this->seconds,
        ];

        if (str_contains($pattern, '%T')) {
            $this->assertNoCalendarComponents(__METHOD__);
            $replacements['%T'] = (string) $this->fixedSeconds();
        }

        return str_replace("\0", '%', strtr($pattern, $replacements));
    }

    public function __toString(): string
    {
        return $this->toISO8601();
    }

    /**
     * @return array{iso8601: string, components: array<string, int>}
     */
    public function jsonSerialize(): mixed
    {
        // Both representations are derived from the same normalized form so
        // they are always consistent. fromISO8601($json['iso8601'])->toArray()
        // will always match $json['components'].
        $n = $this->normalized();
        return [
            'iso8601'    => $n->toISO8601(),
            'components' => $n->toArray(),
        ];
    }


    /**
     * Parses an ISO 8601 duration string into a named-component array.
     * Returns null if the string is invalid or uses unsupported syntax.
     *
     * Single source of truth for parsing, used by both validate() and
     * fromISO8601() to avoid a double regex pass.
     *
     * The regex enforces all structural rules in one place:
     *   - Must start with P and have at least one designator
     *   - T, if present, must be followed by at least one time designator
     *     (lookahead (?=\d) rules out bare "PT" and "P1MT")
     *   - Fractional values (\d+\.\d+) are rejected by \d+ only matching integers
     *
     * @return array{years:int,months:int,days:int,hours:int,minutes:int,seconds:int}|null
     */
    private static function parseComponents(string $value): ?array
    {
        // (?=.) after P ensures the string is not bare "P".
        // (?:T(?=\d)) ensures T is followed by at least one digit, ruling out
        // bare "PT", "P1MT", etc. without a separate post-check.
        if (!preg_match(
            '/^P(?=.)(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?=\d)(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/',
            $value,
            $m,
        )) {
            return null;
        }

        $int = static fn(int $i): int => isset($m[$i]) && $m[$i] !== '' ? (int) $m[$i] : 0;

        return [
            'years'   => $int(1),
            'months'  => $int(2),
            'days'    => $int(3) * 7 + $int(4),
            'hours'   => $int(5),
            'minutes' => $int(6),
            'seconds' => $int(7),
        ];
    }

    /**
     * Fixed seconds from days/hours/minutes/seconds only.
     * Never touches calendar components.
     *
     * The constructor guarantees that the sum of all fixed components does not
     * overflow PHP_INT_MAX (via safeMul + safeAdd), so plain arithmetic is
     * safe here.
     */
    private function fixedSeconds(): int
    {
        return $this->days    * self::SECONDS_IN_DAY
             + $this->hours   * self::SECONDS_IN_HOUR
             + $this->minutes * self::SECONDS_IN_MINUTE
             + $this->seconds;
    }

    /**
     * Adds two integers, throwing OverflowException if the result would exceed
     * PHP_INT_MAX.
     *
     * Assumes non-negative operands. The overflow check is one-directional
     * ($b > 0 guard) and will not catch underflow for negative inputs.
     * This is intentional: the constructor forbids negative components, so
     * negative values should never reach these helpers.
     *
     * @throws \OverflowException
     */
    private static function safeAdd(int $a, int $b, string $component): int
    {
        if ($b > 0 && $a > PHP_INT_MAX - $b) {
            throw new \OverflowException(
                "Adding $b to $a in component \"$component\" would overflow PHP_INT_MAX."
            );
        }
        return $a + $b;
    }

    /**
     * Multiplies two integers, throwing OverflowException if the result would
     * exceed PHP_INT_MAX.
     *
     * Assumes non-negative operands. The guard relies on intdiv(PHP_INT_MAX, $value)
     * being meaningful only for positive $value; negative inputs would bypass
     * the check. See safeAdd() for the same caveat.
     *
     * @throws \OverflowException
     */
    private static function safeMul(int $value, int $factor, string $component): int
    {
        if ($value > 0 && $factor > intdiv(PHP_INT_MAX, $value)) {
            throw new \OverflowException(
                "Multiplying $value by $factor in component \"$component\" would overflow PHP_INT_MAX."
            );
        }
        return $value * $factor;
    }

    /** @throws \RuntimeException */
    private function assertNoCalendarComponents(string $caller): void
    {
        if ($this->hasCalendarComponents()) {
            throw new \RuntimeException(
                "$caller cannot be called on a duration with years or months, "
                . "because those are calendar-dependent. "
                . "Use the *From(DateTimeInterface \$base) variant instead."
            );
        }
    }
}
