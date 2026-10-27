<?php

namespace App\Actions\Lists;

use App\Models\CollectionRecord;
use App\Models\RecordList;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateRecordListAction
{
    /** @param array<string, mixed> $attributes */
    public function execute(User $user, array $attributes): RecordList
    {
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'required', Rule::in(['only_me', 'friends', 'everyone'])],
            'copyId' => ['sometimes', 'required', 'integer', function (string $attribute, mixed $value, $fail) use ($user): void {
                if (! CollectionRecord::visibleTo($user)->whereKey($value)->exists()) {
                    $fail('This record is unavailable.');
                }
            }],
        ])->validate();

        return DB::transaction(function () use ($user, $data): RecordList {
            $list = RecordList::query()->create([...Arr::except($data, ['copyId']), 'user_id' => $user->id]);
            if (isset($data['copyId'])) {
                $copy = CollectionRecord::query()->whereKey($data['copyId'])->firstOrFail();
                $list->entries()->create(['release_id' => $copy->discogs_release_id, 'position' => 0,
                    'collection_record_id' => $copy->user_id === $user->id ? $copy->id : null]);
            }

            return $list;
        });
    }
}
