<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $public_id
 * @property int $record_list_id
 * @property ?int $collection_record_id
 * @property ?int $release_id
 * @property ?string $private_note
 * @property Release $release
 * @property ?CollectionRecord $copy
 */
class RecordListEntry extends Pivot
{
    use HasPublicId;

    protected $table = 'record_list_entries';

    public $incrementing = true;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if ($entry->release_id === null && $entry->collection_record_id !== null) {
                $entry->release_id = CollectionRecord::query()->whereKey($entry->collection_record_id)->value('discogs_release_id');
            }
        });
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return BelongsTo<CollectionRecord, $this> */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_record_id');
    }
}
