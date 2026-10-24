<?php

declare(strict_types=1);

namespace Laravel\Converse\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Converse\Attachment;

/** @implements CastsAttributes<Collection<int, Attachment>, iterable<int, Attachment|array<string, mixed>>> */
class AsAttachment implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Attachment>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Collection
    {
        $stored = is_string($value) ? Json::decode($value) : $value;

        return collect(is_array($stored) ? $stored : [])
            ->map(fn (mixed $attachment): Attachment => Attachment::fromStoredArray((array) $attachment))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) Json::encode(collect($value)
            ->map(fn (mixed $attachment): array => $attachment instanceof Attachment ? $attachment->toArray() : (array) $attachment)
            ->all(), JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
