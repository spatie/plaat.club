<?php

namespace App\Support;

use App\Models\CollectionRecord;
use App\Models\RecordList;
use App\Models\RecordListEntry;
use App\Models\User;
use Illuminate\Support\Arr;

class ListRecords
{
    /** @var array<int, array{releases: list<int>, masters: list<int>}> */
    protected array $ownership = [];

    /** @return array<string, mixed> */
    public function ownership(User $owner, int $releaseId, ?int $masterId): array
    {
        if (! isset($this->ownership[$owner->id])) {
            $copies = $owner->records()->join('releases', 'releases.id', '=', 'collection_records.discogs_release_id')
                ->toBase()->get(['collection_records.discogs_release_id', 'releases.discogs_master_id']);
            $this->ownership[$owner->id] = [
                'releases' => array_values($copies->pluck('discogs_release_id')->unique()->all()),
                'masters' => array_values($copies->pluck('discogs_master_id')->filter()->unique()->all()),
            ];
        }
        $owned = in_array($releaseId, $this->ownership[$owner->id]['releases'], true);

        return ['owned' => $owned, 'otherPressingOwned' => ! $owned && $masterId !== null
            && in_array($masterId, $this->ownership[$owner->id]['masters'], true)];
    }

    /**
     * @param  list<RecordListEntry>  $entries
     * @return list<array<string, mixed>>
     */
    public function summaries(RecordList $list, array $entries, ?User $viewer): array
    {
        $copies = array_map(function (RecordListEntry $entry) use ($list): CollectionRecord {
            $copy = new CollectionRecord;
            $copy->setRelation('release', $entry->release);
            $copy->setRelation('user', $list->user);
            $copy->discogs_release_id = $entry->release_id;
            $copy->instance_id = $entry->id;
            $copy->public_id = $entry->public_id;
            $copy->added_at = $list->created_at;

            return $copy;
        }, $entries);
        $summaries = app(RecordCollection::class)->profileSummaries($copies);

        return array_map(fn (array $summary, RecordListEntry $entry): array => [
            ...Arr::except($summary, ['shareUrl']),
            'entryPublicId' => $entry->public_id, 'releaseId' => $entry->release_id,
            'catalogNumber' => $entry->release->catalog_number ?? '',
            'discogsUrl' => 'https://www.discogs.com/release/'.$entry->release_id,
            ...($entry->copy === null ? [] : ['copyId' => $entry->copy->id, 'copyPublicId' => $entry->copy->public_id]),
            'cover' => filled($entry->release->cover) && $entry->release->cover !== '/images/record-placeholder.svg'
                ? route('lists.entries.cover', [$list, $entry], false) : '/images/record-placeholder.svg',
            'shareUrl' => route('lists.show', $list, false).'#record-'.$entry->public_id,
            ...($viewer?->id === $list->user_id ? [
                ...$this->ownership($viewer, $entry->release_id, $entry->release->discogs_master_id),
                'privateNote' => $entry->private_note,
            ] : []),
        ], $summaries, $entries);
    }
}
