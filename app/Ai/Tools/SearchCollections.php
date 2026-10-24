<?php

namespace App\Ai\Tools;

use App\Actions\Collections\SearchCollectionsAction;
use App\Actions\Collections\ValidateCollectionQuestionAction;
use App\Ai\ToolResult;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Tools\Request;

class SearchCollections implements Tool
{
    /** @var list<array<string, mixed>> */
    public array $results = [];

    public function __construct(
        protected User $viewer,
        protected string $question,
        protected string $source,
    ) {}

    public function description(): string
    {
        return 'Search or count records in currently visible collections, find collectors by name, compare whole collections, or recommend from indexed metadata. Use intent count for number-only questions; this counts collection copies and displays no cards. Searches return up to 24 relevant releases, never notes. Use exact quoted evidence for genres and subjective requests.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        $properties = [];
        foreach (['target_handle', 'person', 'person_quote', 'exclude_artist', 'title', 'label', 'track', 'semantic_query', 'semantic_quote'] as $field) {
            $properties[$field] = $schema->string()->nullable()->required();
        }
        $properties['intent'] = $schema->string()->enum(['search', 'count', 'compare'])->required();
        $properties['scope'] = $schema->string()->enum(['collection', 'friends'])->required();
        foreach (['genre', 'style'] as $field) {
            $properties[$field] = $schema->object([
                'value' => $schema->string()->required(),
                'quote' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->nullable()->required();
        }
        foreach (['role' => ['Any', 'Producer', 'Drums', 'Saxophone', 'Guitar', 'Bass', 'Piano', 'Vocals', 'Mastered By', 'Written-By', 'Composed By', 'Recorded By', 'Mixed By'], 'format' => ['Vinyl', 'CD', 'Cassette'], 'release_type' => ['Single', 'EP', 'Album'], 'year_extreme' => ['oldest', 'newest'], 'order' => ['recently_added']] as $field => $values) {
            $properties[$field] = $schema->string()->enum([null, ...$values])->nullable()->required();
        }
        foreach (['year_from', 'year_to'] as $field) {
            $properties[$field] = $schema->integer()->description($field === 'year_from'
                ? 'Inclusive earliest original release year. After Y means Y + 1. Retain an existing upper bound on narrowing follow-ups.'
                : 'Inclusive latest original release year. Before Y means Y - 1. Retain an existing lower bound on narrowing follow-ups.')->nullable()->required();
        }
        $properties['unsupported'] = $schema->array()->items($schema->string())->required();

        return $properties;
    }

    /** @return array<string, mixed> */
    public function parameters(Request $request): array
    {
        $rules = [
            'intent' => ['required', Rule::in(['search', 'count', 'compare', 'lists'])],
            'scope' => ['required', Rule::in(['collection', 'friends'])],
            'unsupported' => ['present', 'array', 'max:10'],
            'unsupported.*' => ['string', 'max:300'],
        ];
        foreach (['target_handle', 'person', 'person_quote', 'exclude_artist', 'title', 'label', 'track', 'semantic_query', 'semantic_quote'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:300'];
        }
        foreach (['role' => ['Any', 'Producer', 'Drums', 'Saxophone', 'Guitar', 'Bass', 'Piano', 'Vocals', 'Mastered By', 'Written-By', 'Composed By', 'Recorded By', 'Mixed By'], 'format' => ['Vinyl', 'CD', 'Cassette'], 'release_type' => ['Single', 'EP', 'Album'], 'year_extreme' => ['oldest', 'newest'], 'order' => ['recently_added']] as $field => $values) {
            $rules[$field] = ['nullable', Rule::in($values)];
        }
        foreach (['year_from', 'year_to'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:1', 'max:2100'];
        }
        foreach (['genre', 'style'] as $field) {
            $rules[$field] = ['nullable', 'array:value,quote'];
            $rules["{$field}.value"] = ['required_with:'.$field, 'string', 'max:100'];
            $rules["{$field}.quote"] = ['required_with:'.$field, 'string', 'max:300'];
        }
        $data = $request->validate($rules);

        return [...array_fill_keys(['target_handle', 'person', 'person_quote', 'exclude_artist', 'title', 'label', 'track', 'semantic_query', 'semantic_quote', 'role', 'format', 'release_type', 'year_extreme', 'order', 'year_from', 'year_to', 'genre', 'style', 'list'], null), ...$data];
    }

    public function handle(Request $request): string
    {
        try {
            return $this->execute($request);
        } catch (ValidationException $exception) {
            return ToolResult::encode(['error' => implode("\n", $exception->validator->errors()->all())]);
        } catch (AiException|ConnectionException) {
            return ToolResult::encode(['error' => 'Recommendations are temporarily unavailable. No substitute search was used.']);
        }
    }

    protected function execute(Request $request): string
    {
        $plan = $this->parameters($request);
        if (! in_array($plan['intent'], ['search', 'count', 'compare'], true)) {
            throw ValidationException::withMessages(['intent' => 'Use ProposeRecordList for list proposals.']);
        }
        $plan = app(ValidateCollectionQuestionAction::class)->execute($plan, $this->question, $this->source);
        $result = app(SearchCollectionsAction::class)->execute($this->viewer, $this->viewer, $plan);
        $result['countOnly'] = $plan['intent'] === 'count';
        if ($result['countOnly']) {
            if (isset($result['copyIds'])) {
                $result['total'] = count($result['copyIds']);
                $result['message'] = "There are {$result['total']} matching collection records.";
            }
            $result['records'] = [];
        }
        $result['selectionAllowed'] = $plan['semantic_query'] === null;
        $this->results[] = $result;

        if ($result['countOnly']) {
            return ToolResult::encode([
                'message' => $result['message'],
                'count' => isset($result['copyIds']) ? $result['total'] : null,
                'unit' => 'collection copies',
            ]);
        }

        return ToolResult::encode(Arr::except($result, ['copyIds']));
    }
}
