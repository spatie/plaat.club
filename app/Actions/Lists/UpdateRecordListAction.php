<?php

namespace App\Actions\Lists;

use App\Models\RecordList;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateRecordListAction
{
    /** @param array<string, mixed> $attributes */
    public function execute(User $user, RecordList $recordList, array $attributes): void
    {
        abort_unless($recordList->user_id === $user->id, 404);

        $data = Validator::make($attributes, [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'required', Rule::in(['only_me', 'friends', 'everyone'])],
            'recordIds' => ['sometimes', 'array', 'list', 'max:1000'],
            'recordIds.*' => ['required', 'integer', 'distinct', Rule::exists('collection_records', 'id')->where('user_id', $user->id)],
            'recordMode' => ['sometimes', Rule::in(['add', 'remove', 'replace'])],
            'entries' => ['sometimes', 'array', 'list', 'max:1000', 'prohibits:recordIds'],
            'entries.*.entryPublicId' => ['sometimes', 'required', 'uuid', 'distinct'],
            'entries.*.releaseId' => ['required', 'integer', 'min:1', 'max:9999999999'],
        ])->validate();

        if (array_key_exists('entries', $data)) {
            foreach ($data['entries'] as $position => $entry) {
                if (isset($entry['entryPublicId'])) {
                    if (! $recordList->entries()->where('public_id', $entry['entryPublicId'])->where('release_id', $entry['releaseId'])->exists()) {
                        throw ValidationException::withMessages(["entries.{$position}.entryPublicId" => 'This entry is not part of this list or does not match this release.']);
                    }

                    continue;
                }
                app(ImportListReleaseAction::class)->execute($user, $entry['releaseId']);
            }
        }

        DB::transaction(function () use ($recordList, $data): void {
            $recordList = RecordList::query()->lockForUpdate()->findOrFail($recordList->id);
            $recordList->update(Arr::only($data, ['name', 'description', 'visibility']));
            if (array_key_exists('recordIds', $data)) {
                $mode = $data['recordMode'] ?? 'replace';
                if ($mode === 'remove') {
                    $recordList->entries()->whereIn('collection_record_id', $data['recordIds'])->delete();
                    $recordList->touch();

                    return;
                }
                $entries = [];
                $existingCopyIds = $recordList->entries()->pluck('collection_record_id')->all();
                $position = $mode === 'add' ? (($recordList->entries()->max('position') ?? -1) + 1) : 0;
                foreach ($data['recordIds'] as $id) {
                    if ($mode === 'add' && in_array($id, $existingCopyIds)) {
                        continue;
                    }
                    $entries[$id] = ['position' => $position++];
                }
                if ($mode === 'add') {
                    $recordList->records()->attach($entries);
                } else {
                    $recordList->records()->sync($entries);
                    $recordList->entries()->whereNull('collection_record_id')->delete();
                }
                if ($recordList->entries()->count() > 1000) {
                    throw ValidationException::withMessages(['recordIds' => 'Lists can contain up to 1,000 records.']);
                }
                $recordList->touch();
            }
            if (array_key_exists('entries', $data)) {
                $keptIds = [];
                foreach ($data['entries'] as $position => $attributes) {
                    $entry = isset($attributes['entryPublicId'])
                        ? $recordList->entries()->where('public_id', $attributes['entryPublicId'])->where('release_id', $attributes['releaseId'])->first()
                        : $recordList->entries()->make(['release_id' => $attributes['releaseId']]);
                    if ($entry === null) {
                        throw ValidationException::withMessages(['entries' => 'The list changed. Please reload before saving.']);
                    }
                    $entry->position = $position;
                    $entry->save();
                    $keptIds[] = $entry->id;
                }
                $recordList->entries()->whereNotIn('id', $keptIds)->delete();
                $recordList->touch();
            }
        });
    }
}
