<?php

namespace App\Ai\Tools;

use App\Actions\Lists\CreateRecordListAction;
use App\Actions\Lists\DeleteRecordListAction;
use App\Actions\Lists\UpdateRecordListAction;
use App\Models\RecordList;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class WriteRecordList implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(protected User $user) {}

    public function description(): string
    {
        return 'Create, update or delete your own record list. Requires confirmation. Record IDs must belong to your collection. New lists are private by default.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'update', 'delete'])->required(),
            'list' => $schema->string()->nullable()->required(),
            'name' => $schema->string()->nullable()->required(),
            'description' => $schema->string()->nullable()->required(),
            'visibility' => $schema->string()->enum([null, 'only_me', 'friends', 'everyone'])->nullable()->required(),
            'recordMode' => $schema->string()->enum([null, 'add', 'remove'])->nullable()->required(),
            'recordIds' => $schema->array()->items($schema->integer())->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function prepare(Request $request): array
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['create', 'update', 'delete'])],
            'list' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['nullable', Rule::in(['only_me', 'friends', 'everyone'])],
            'recordMode' => ['nullable', Rule::in(['add', 'remove'])],
            'recordIds' => ['present', 'array', 'list', 'max:1000'],
            'recordIds.*' => ['required', 'integer', 'distinct', Rule::exists('collection_records', 'id')->where('user_id', $this->user->id)],
        ]);
        if ($data['recordIds'] !== [] && ($data['recordMode'] ?? null) === null) {
            throw ValidationException::withMessages(['records' => 'Specify whether records should be added or removed.']);
        }
        if ($data['action'] === 'create') {
            if (blank($data['name'] ?? null)) {
                throw ValidationException::withMessages(['name' => 'Name the list you want to create.']);
            }
            if (($data['recordMode'] ?? null) === 'remove') {
                throw ValidationException::withMessages(['records' => 'A new list has no records to remove.']);
            }
            $data['visibility'] ??= 'only_me';
            $data['list'] = null;
        } else {
            if (blank($data['list'] ?? null)) {
                throw ValidationException::withMessages(['list' => 'Name the exact list you want to change.']);
            }
            $matches = RecordList::query()->where('user_id', $this->user->id)
                ->where(fn ($query) => $query->where('name', $data['list'])
                    ->when(Str::isUuid($data['list']), fn ($query) => $query->orWhere('public_id', $data['list'])))->limit(2)->get();
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['list' => 'That list is unavailable or its name is ambiguous. Read your lists and use its exact public UUID.']);
            }
            $data['list'] = $matches->first()->public_id;
            $data['currentName'] = $matches->first()->name;
            $data['currentVisibility'] = $matches->first()->visibility;
            $data['state'] = $this->state($matches->first());
        }

        return $data;
    }

    public function handle(Request $request): string
    {
        return DB::transaction(function () use ($request): string {
            $data = $this->prepare($request);
            $list = $data['action'] === 'create' ? null : RecordList::query()->where('user_id', $this->user->id)
                ->where('public_id', $data['list'])->lockForUpdate()->firstOrFail();
            if ($list !== null && ($request['state'] ?? null) !== null && $request['state'] !== $this->state($list)) {
                throw ValidationException::withMessages(['list' => 'The list changed after the preview. Ask again to review its current state.']);
            }
            if ($data['action'] === 'delete') {
                app(DeleteRecordListAction::class)->execute($this->user, $list);

                return json_encode(['action' => 'deleted', 'name' => $list->name], JSON_THROW_ON_ERROR);
            }
            $attributes = array_filter(Arr::only($data, ['name', 'description', 'visibility']), fn ($value): bool => $value !== null);
            $list ??= app(CreateRecordListAction::class)->execute($this->user, $attributes);
            if (($data['recordMode'] ?? null) !== null) {
                $existing = $list->records()->pluck('collection_records.id')->all();
                $attributes['recordIds'] = $data['recordMode'] === 'add'
                    ? array_values(array_unique([...$existing, ...$data['recordIds']]))
                    : array_values(array_diff($existing, $data['recordIds']));
            }
            app(UpdateRecordListAction::class)->execute($this->user, $list, $attributes);
            $list->refresh();

            return json_encode([
                'action' => $data['action'] === 'create' ? 'created' : 'updated',
                'publicId' => $list->public_id, 'description' => $list->description,
                'covers' => $list->records()->without('release')->limit(4)->get(['collection_records.id', 'collection_records.public_id'])->map(fn ($copy): string => route('lists.cover', [$list, $copy], false))->all(),
                'name' => $list->name, 'url' => route('lists.show', $list),
                'visibility' => $list->visibility, 'count' => $list->records()->count(),
            ], JSON_THROW_ON_ERROR);
        });
    }

    protected function state(RecordList $list): string
    {
        return hash('sha256', json_encode([
            $list->only(['name', 'description', 'visibility']),
            $list->records()->pluck('collection_records.id')->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
