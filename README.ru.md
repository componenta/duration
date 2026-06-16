# Componenta Duration

Иммутабельный объект значения для длительностей ISO 8601 с календарными и фиксированными операциями.

Используйте его, когда коду приложения нужна типизированная длительность вместо сырых секунд или сырых ISO-строк.

## Установка

```bash
composer require componenta/duration
```

## Связанные пакеты

Пакет самодостаточный: для создания и сравнения длительностей соседние пакеты не нужны.

| Пакет | Зачем может использоваться рядом |
|---|---|
| `componenta/clock` | Даёт опорную дату для календарных сравнений и применения длительности ко времени. |
| `componenta/config` | Может хранить интервалы в ISO 8601 строках, которые затем превращаются в `Duration`. |
| `componenta/validation` | Может валидировать пользовательскую строку длительности до создания объекта значения. |

## Использование

```php
use Componenta\Stdlib\Duration;

$duration = Duration::fromISO8601('PT90S');

$duration->toISO8601(); // "PT1M30S"
$duration->toSeconds(); // 90
$duration->humanize();  // "1 minute 30 seconds"
```

Для типовых единиц есть фабрики:

```php
Duration::ofDays(2);
Duration::ofHours(6);
Duration::fromSeconds(3600);
Duration::between($start, $end);
```

## Календарные компоненты

Годы и месяцы являются календарными компонентами. Они не конвертируются в секунды без опорной даты, потому что точная длина зависит от календаря.

```php
$duration = Duration::ofMonths(1);

$duration->hasCalendarComponents(); // true
$duration->isFixed();               // false
$duration->toSeconds();             // throws RuntimeException
$duration->toSecondsFrom(new DateTimeImmutable('2026-01-01'));
```

Недели разворачиваются в дни при разборе ISO 8601, потому что объект значения хранит дни, а не отдельный компонент недели.

## Арифметика

`Duration` поддерживает иммутабельные арифметические операции:

- `add`
- `subtract`
- `subtractClamped`
- `multiply`
- `divideBy`
- `sum`
- `min`
- `max`

Используйте `compareTo()` только для сопоставимых длительностей. Календарное сравнение доступно через `compareToFrom()` с опорной датой.

## Конвертация

Объект может конвертироваться в:

- строку ISO 8601
- `DateInterval`
- плотный массив через `toArray()`
- разреженный массив через `toSparseArray()`
- пользовательскую строку через `format()`
- JSON через `jsonSerialize()`

`applyTo()` добавляет длительность к `DateTimeInterface` и возвращает `DateTimeImmutable`.

## Поддерживаемый формат

Парсер поддерживает целочисленные компоненты ISO 8601: `P[n]Y[n]M[n]W[n]DT[n]H[n]M[n]S`.

Дробные компоненты вроде `PT1.5S` отклоняются.
