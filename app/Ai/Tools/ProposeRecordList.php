<?php

namespace App\Ai\Tools;

use App\Actions\Collections\SearchCollectionsAction;
use App\Actions\Collections\ValidateCollectionQuestionAction;
use App\Ai\ToolResult;
use App\Models\RecordList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;

class ProposeRecordList extends SearchCollections
{
    /** @var array<string, mixed>|null */
    public ?array $pending = null;

    public ?string $recentList = null;

    /** @var list<int> */
    public array $selectableRecords = [];

    public ?SearchCollections $search = null;

    public ?ReadRecordLists $lists = null;

    public function description(): string
    {
        return 'Prepare exactly one list change for local confirmation. Does not write. Set intent lists and provide list action/target/use_recent/name/description/visibility/records. Optional factual filters select ALL own matching copies; recordIds selects specific trusted copies instead.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [...parent::schema($schema),
            'intent' => $schema->string()->enum(['lists'])->required(),
            'list' => $schema->object([
                'action' => $schema->string()->enum(['create', 'update', 'delete'])->required(),
                'target' => $schema->string()->nullable()->required(),
                'use_recent' => $schema->boolean()->required(),
                'name' => $schema->string()->nullable()->required(),
                'description' => $schema->string()->nullable()->required(),
                'visibility' => $schema->string()->enum([null, 'only_me', 'friends', 'everyone'])->nullable()->required(),
                'records' => $schema->string()->enum([null, 'add', 'remove'])->nullable()->required(),
            ])->withoutAdditionalProperties()->required(),
            'recordIds' => $schema->array()->items($schema->integer())->required(),
        ];
    }

    protected function execute(Request $request): string
    {
        if ($this->pending !== null) {
            throw ValidationException::withMessages(['list' => 'Only one list change can be proposed per question.']);
        }
        $data = $request->validate([
            'list' => ['required', 'array:action,target,use_recent,name,description,visibility,records'],
            'list.action' => ['required', 'in:create,update,delete'],
            'list.target' => ['nullable', 'string', 'max:120'],
            'list.use_recent' => ['sometimes', 'boolean'],
            'list.name' => ['nullable', 'string', 'max:120'],
            'list.description' => ['nullable', 'string', 'max:2000'],
            'list.visibility' => ['nullable', 'in:only_me,friends,everyone'],
            'list.records' => ['nullable', 'in:add,remove'],
            'recordIds' => ['sometimes', 'array', 'list', 'max:1000'],
            'recordIds.*' => ['integer', 'distinct'],
        ]);
        $plan = [...$this->parameters($request), 'list' => $data['list']];
        if ($plan['intent'] !== 'lists') {
            throw ValidationException::withMessages(['intent' => 'A proposal must be a list operation.']);
        }
        $plan = app(ValidateCollectionQuestionAction::class)->execute($plan, $this->question, $this->source, $this->recentList !== null);
        $list = $plan['list'];
        if ($list['use_recent'] ?? false) {
            $recent = RecordList::query()->where('user_id', $this->viewer->id)->where('public_id', $this->recentList)->first();
            if ($recent === null || $list['action'] === 'create') {
                throw ValidationException::withMessages(['list' => 'Which list do you mean? Include its name.']);
            }
            $list['target'] = $recent->public_id;
        }
        $copies = $data['recordIds'] ?? [];
        $selectable = $this->selectableRecords;
        foreach ($this->search->results ?? [] as $result) {
            if ($result['selectionAllowed']) {
                $selectable = [...$selectable, ...array_column($result['records'], 'recordId')];
            }
        }
        foreach ($this->lists->results ?? [] as $result) {
            foreach ($result['lists'] as $item) {
                $selectable = [...$selectable, ...array_column($item['records'], 'recordId')];
            }
        }
        if (array_diff($copies, $selectable) !== []) {
            throw ValidationException::withMessages(['records' => 'Select specific records only from trusted factual results in this conversation. Recommendations cannot select list contents.']);
        }
        $filters = [];
        if ($list['records'] !== null) {
            if (! in_array($list['action'], ['create', 'update'], true)) {
                throw ValidationException::withMessages(['list' => 'Ask for one list operation at a time.']);
            }
            $hasFilters = collect($plan)->except(['intent', 'scope', 'unsupported', 'list', 'person_quote', 'semantic_quote'])->filter(fn ($value): bool => $value !== null)->isNotEmpty();
            if ($copies !== [] && $hasFilters) {
                throw ValidationException::withMessages(['records' => 'Use factual filters or explicit record IDs, not both.']);
            }
            if ($copies === []) {
                $result = app(SearchCollectionsAction::class)->execute($this->viewer, $this->viewer, $plan);
                $copies = $result['copyIds'];
                $filters = $result['filters'];
            }
        }
        $arguments = (new WriteRecordList($this->viewer))->prepare(new Request([
            'action' => $list['action'], 'list' => $list['target'], 'name' => $list['name'],
            'description' => $list['description'], 'visibility' => $list['visibility'],
            'recordMode' => $list['records'], 'recordIds' => $copies,
        ]));
        $preview = [
            'operation' => $arguments['action'], 'list' => $arguments['currentName'] ?? $arguments['name'],
            'newName' => $arguments['name'], 'description' => $arguments['description'],
            'visibility' => $arguments['visibility'] ?? $arguments['currentVisibility'] ?? 'only_me',
            'records' => $list['records'] === null ? 'unchanged' : $list['records'].' '.count($copies).' collection copies',
            'filters' => $filters,
        ];
        $this->pending = ['arguments' => $arguments, 'preview' => $preview];

        return ToolResult::encode(['status' => 'proposal_only', ...$preview]);
    }
}
