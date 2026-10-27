<?php

namespace App\Actions\Lists;

use App\Models\CollectionRecord;
use App\Models\RecordList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRecordListMembershipAction
{
    public function execute(User $user, RecordList $recordList, CollectionRecord $copy, bool $containsRecord): void
    {
        abort_unless($recordList->user_id === $user->id, 404);
        abort_unless($copy->user->canViewCollection($user), 404);

        DB::transaction(function () use ($user, $recordList, $copy, $containsRecord): void {
            $list = RecordList::query()->lockForUpdate()->findOrFail($recordList->id);

            if (! $containsRecord) {
                if ($list->entries()->where('release_id', $copy->discogs_release_id)->delete()) {
                    $list->touch();
                }

                return;
            }

            if ($list->entries()->where('release_id', $copy->discogs_release_id)->exists()) {
                return;
            }

            if ($list->entries()->count() >= 1000) {
                throw ValidationException::withMessages(['record' => 'Lists can contain up to 1,000 records.']);
            }

            $position = DB::table('record_list_entries')->where('record_list_id', $list->id)->max('position');
            $list->entries()->create(['release_id' => $copy->discogs_release_id,
                'collection_record_id' => $copy->user_id === $user->id ? $copy->id : null,
                'position' => $position === null ? 0 : $position + 1]);
            $list->touch();
        });
    }
}
