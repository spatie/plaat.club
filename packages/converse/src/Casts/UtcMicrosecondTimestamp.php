<?php

declare(strict_types=1);

namespace Laravel\Converse\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string> */
class UtcMicrosecondTimestamp implements CastsAttributes
{
    public const string FORMAT = 'Y-m-d H:i:s.u';

    public static function format(DateTimeInterface|string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format(static::FORMAT);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof DateTimeInterface || is_string($value) ? static::format($value) : null;
    }
}
