<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * @property int $id
 * @property string $resource_type
 * @property int $discogs_id
 * @property array<string, mixed> $response
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable $expires_at
 */
class DiscogsResponse extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public static function compactResponse(string $resourceType, array $response): array
    {
        return Arr::only($response, match ($resourceType) {
            'releases' => ['id', 'title', 'year', 'released', 'artists', 'labels', 'master_id', 'notes', 'extraartists', 'tracklist'],
            'masters' => ['id', 'year'],
            'artists' => ['id', 'name', 'profile'],
            'labels' => ['id', 'name'],
            default => array_keys($response),
        });
    }

    /** @return Builder<DiscogsResponse> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }

    protected function casts(): array
    {
        return [
            'discogs_id' => 'integer',
            'response' => 'array',
            'fetched_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
