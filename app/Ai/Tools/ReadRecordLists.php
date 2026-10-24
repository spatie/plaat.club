<?php

namespace App\Ai\Tools;

use App\Ai\ToolResult;
use App\Models\RecordList;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ReadRecordLists implements Tool
{
    /** @var list<array<string, mixed>> */
    public array $results = [];

    public function __construct(protected User $user) {}

    public function description(): string
    {
        return 'Read your own record lists, or show the records in one list by its exact name or public UUID.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['list' => $schema->string()->nullable()->required()];
    }

    public function handle(Request $request): string
    {
        try {
            return $this->read($request);
        } catch (ValidationException $exception) {
            return ToolResult::encode(['error' => implode("\n", $exception->validator->errors()->all())]);
        }
    }

    protected function read(Request $request): string
    {
        $data = $request->validate(['list' => ['nullable', 'string', 'max:120']]);
        $query = RecordList::query()->where('user_id', $this->user->id)->withCount('records')->orderBy('name')->orderBy('id');
        if (filled($data['list'] ?? null)) {
            $query->where(fn ($query) => $query->where('name', $data['list'])
                ->when(Str::isUuid($data['list']), fn ($query) => $query->orWhere('public_id', $data['list'])));
        }
        $total = (clone $query)->count();
        if (filled($data['list'] ?? null) && $total !== 1) {
            throw ValidationException::withMessages(['list' => $total === 0 ? 'That list is unavailable. Use an exact name or public UUID from your own lists.' : 'Several lists have that name. Ask to see your lists and use the exact public UUID.']);
        }

        $lists = $query->limit(50)->get()->map(function (RecordList $list) use ($data): array {
            return [
                'publicId' => $list->public_id,
                'name' => $list->name,
                'description' => $list->description,
                'visibility' => $list->visibility,
                'count' => $list->records_count,
                'url' => route('lists.show', $list),
                'covers' => $list->records()->without('release')->limit(4)->get(['collection_records.id', 'collection_records.public_id'])->map(fn ($copy): string => route('lists.cover', [$list, $copy], false))->all(),
                'records' => filled($data['list'] ?? null) ? $list->records()->limit(24)->get()->map(fn ($copy): array => [
                    'artist' => $copy->payload['artist'], 'title' => $copy->payload['title'],
                    'recordId' => $copy->id,
                    'year' => $copy->payload['originalYear'], 'url' => $copy->url(false),
                    'mediaFormats' => $copy->formats(),
                    'cover' => route('lists.cover', [$list, $copy], false), 'evidence' => [],
                ])->all() : [],
            ];
        });

        $result = ['total' => $total, 'lists' => $lists->all()];
        $this->results[] = $result;

        return ToolResult::encode($result);
    }
}
