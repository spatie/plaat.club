<?php

namespace App\Support;

use App\Actions\Collections\StoreCollectionRecordAction;
use App\Actions\Collections\StoreCollectionRecordsAction;
use App\Models\CollectionRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class RecordCollection
{
    public function __construct(
        protected RecordReleaseDates $dates,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function records(): array
    {
        if (! CollectionRecord::legacy()->exists() && User::freek()->collection_imported_at === null) {
            return array_map(
                fn (array $record): array => [...$record, 'publicId' => Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    hash_hmac('sha256', 'snapshot-record:'.$record['instanceId'], config('app.key')),
                )->toString()],
                $this->snapshot(),
            );
        }

        return CollectionRecord::legacy()
            ->orderByDesc('added_at')
            ->orderByDesc('instance_id')
            ->get()->map(fn (CollectionRecord $record): array => [...$record->payload, 'publicId' => $record->public_id])
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function find(int $instanceId): ?array
    {
        if (! CollectionRecord::legacy()->exists() && User::freek()->collection_imported_at === null) {
            return collect($this->snapshot())->firstWhere('instanceId', $instanceId);
        }

        return CollectionRecord::legacy()->where('instance_id', $instanceId)->first()?->payload;
    }

    /** @return array<string, mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        if (! CollectionRecord::legacy()->exists() && User::freek()->collection_imported_at === null) {
            return collect($this->records())->firstWhere('publicId', $publicId);
        }

        $record = CollectionRecord::legacy()->where('public_id', $publicId)->first();

        return $record ? [...$record->payload, 'publicId' => $record->public_id] : null;
    }

    /**
     * The records without their tracklists and the fields the shelf never
     * shows, enriched with release dates and share metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summaries(): array
    {
        return array_map($this->summary(...), $this->dates->enrich($this->records()));
    }

    /** @return array<string, mixed>|null */
    public function findSummary(int $instanceId): ?array
    {
        return collect($this->summaries())->firstWhere('instanceId', $instanceId);
    }

    /** @return array<int, array<string, mixed>> */
    public function latest(int $count): array
    {
        return array_slice($this->summaries(), 0, $count);
    }

    /**
     * @param  list<CollectionRecord>  $copies
     * @return list<array<string, mixed>>
     */
    public function profileSummaries(array $copies): array
    {
        // Never use the legacy owner's cache or serialize an entire model/payload.
        $records = array_map(fn (CollectionRecord $copy): array => [
            ...Arr::only($copy->payload, [
                'artist', 'artists', 'title', 'displayTitle', 'year', 'originalYear',
                'label', 'format', 'genres', 'styles', 'cover', 'coverPreview', 'tracks',
                'appleMusicUrl', 'appleMusicSearch', 'spotifyUrl', 'spotifySearch',
                'bandcampUrl', 'bandcampSearch',
            ]),
            'id' => $copy->discogs_release_id, 'instanceId' => $copy->instance_id,
            'publicId' => $copy->public_id,
            'addedAt' => $copy->added_at->toIso8601String(),
            'mediaFormats' => $copy->formats(),
            'cover' => filled($copy->payload['cover'] ?? null) && $copy->payload['cover'] !== '/images/record-placeholder.svg'
                ? route('shelf.cover', $copy, false)
                : ($copy->payload['cover'] ?? '/images/record-placeholder.svg'),
        ], $copies);

        return array_map(
            fn (array $record, CollectionRecord $copy): array => $this->summary($record, $copy->url(false)),
            $this->dates->enrich($records), $copies,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function snapshot(): array
    {
        $records = File::json(resource_path('data/collection.json'))['records'] ?? null;

        if (! is_array($records)) {
            throw new RuntimeException('The bundled collection snapshot is unavailable.');
        }

        return $records;
    }

    /** @param array<string, mixed> $record */
    public function store(array $record): void
    {
        $copy = User::freek()->records()->firstOrNew(['instance_id' => $record['instanceId']]);
        app(StoreCollectionRecordAction::class)->execute($copy, [
            'discogs_release_id' => $record['id'],
            'added_at' => CarbonImmutable::parse($record['addedAt'])->utc()->toDateTimeString(),
            'payload' => $record,
        ]);
    }

    /** @param array<int, array<string, mixed>> $records */
    public function storeMany(array $records): void
    {
        app(StoreCollectionRecordsAction::class)->execute(User::freek(), $records);
    }

    /** @param array<int, int> $instanceIds */
    public function removeAllExcept(array $instanceIds): int
    {
        return CollectionRecord::legacy()->whereNotIn('instance_id', $instanceIds)->delete();
    }

    /** @param array<string, mixed> $record */
    public function slug(array $record): string
    {
        return Str::slug("{$record['artist']} {$record['displayTitle']}");
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function summary(array $record, ?string $profileUrl = null): array
    {
        $year = $record['originalYear'] ? " ({$record['originalYear']})" : '';

        $record['trackTitles'] = array_column($record['tracks'], 'title');
        $record['shareUrl'] = $profileUrl ?? route('recordShare', [
            'instanceId' => $record['publicId'],
            'slug' => $this->slug($record),
        ], false);
        if ($profileUrl === null) {
            $record['shareDescription'] = "{$record['displayTitle']} by {$record['artist']}{$year}, from Freek's vinyl collection. Explore the artwork, tracklist and links to listen.";
        }

        return Arr::except($record, [
            'tracks',
            'artistBios',
            'releaseNotes',
            'credits',
            'discogsTextLinks',
            'catalogNumber',
            'coverSource',
            'discogsUrl',
            'edition',
            'musicbrainzId',
            'originalDateSource',
            'pressingReleaseDate',
            'trackSource',
        ]);
    }
}
