<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** An instant stored as a UTC 'Y-m-d H:i:s' string and always read back as UTC, whatever the application timezone is. */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        return ($value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC'))->utc()->format('Y-m-d H:i:s');
    }
}
