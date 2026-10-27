<?php

namespace App\Support;

use App\Models\CollectionRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProfileCollection
{
    /** @var list<array{name: string, count: int, offset: int}>|null */
    protected ?array $groups = null;

    public function __construct(protected User $user, protected Request $request) {}

    /** @return array{mode: string, group: ?string, query: string} */
    public function state(): array
    {
        return [
            'mode' => in_array($this->request->query('mode'), ['decade', 'genre', 'artist'], true) ? $this->request->query('mode') : 'recent',
            'group' => is_string($this->request->query('group')) ? mb_substr($this->request->query('group'), 0, 255) : null,
            'query' => is_string($this->request->query('q')) ? mb_substr($this->request->query('q'), 0, 200) : '',
        ];
    }

    /** @return list<array{name: string, count: int, offset: int}> */
    public function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $mode = $this->state()['mode'];
        $latest = match (DB::getDriverName()) {
            'mysql' => "MAX(CONCAT(records.added_at, LPAD(records.id, 20, '0')))",
            'pgsql' => "MAX(CONCAT(records.added_at, LPAD(CAST(records.id AS TEXT), 20, '0')))",
            default => "MAX(records.added_at || printf('%020d', records.id))",
        };
        $counts = $this->query()->selectRaw($this->sectionExpression()." AS section, COUNT(*) AS total, {$latest} AS latest")
            ->groupBy('section')->get()->all();
        usort($counts, fn ($first, $second) => match ($mode) {
            'recent' => strcmp($second->section, $first->section),
            'decade' => (int) $second->section <=> (int) $first->section,
            default => strnatcasecmp(Str::ascii($first->section), Str::ascii($second->section)) ?: strcmp($second->latest, $first->latest),
        });

        $this->groups = [];
        $offset = 0;
        foreach ($counts as $count) {
            $this->groups[] = ['name' => $this->sectionName($count->section), 'count' => (int) $count->total, 'offset' => $offset];
            $offset += (int) $count->total;
        }

        return $this->groups;
    }

    /** @return LengthAwarePaginator<int, non-empty-array<string, mixed>> */
    public function records(): LengthAwarePaginator
    {
        $groups = $this->groups();
        $total = array_sum(array_column($groups, 'count'));
        $perPage = 36;
        $offset = collect($groups)->firstWhere('name', $this->state()['group'])['offset'] ?? 0;
        $page = $this->request->has('recordsPage') ? max(1, $this->request->integer('recordsPage')) : intdiv($offset, $perPage) + 1;
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $slice = $this->entries(($page - 1) * $perPage, $perPage);
        $copies = $this->user->records()->whereIn('id', array_column($slice, 'copyId'))
            ->with('user')->get(['id', 'public_id', 'user_id', 'instance_id', 'discogs_release_id', 'added_at'])->keyBy('id');
        $summaries = app(RecordCollection::class)->profileSummaries(array_map(fn (array $entry) => $copies->get($entry['copyId']), $slice));
        $items = array_map(fn (array $summary, array $entry) => [...$summary, 'section' => $entry['section'], 'key' => $entry['key']], $summaries, $slice);

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => $this->request->url(), 'pageName' => 'recordsPage',
            'query' => $this->request->only(['mode', 'q', 'group']),
        ]);
    }

    /** @return list<int> */
    public function matchingReleaseIds(): array
    {
        return array_values($this->query()->distinct()->orderBy('records.discogs_release_id')->pluck('records.discogs_release_id')
            ->map(fn ($releaseId): int => (int) $releaseId)->all());
    }

    protected function query(bool $withDates = false): Builder
    {
        $state = $this->state();
        $query = CollectionRecord::query()->from('collection_records as records')->where('records.user_id', $this->user->id);
        if (in_array($state['mode'], ['artist', 'genre'], true)) {
            $query->join('collection_browse_entries as entries', 'entries.collection_record_id', '=', 'records.id')
                ->where('entries.user_id', $this->user->id)->where('entries.kind', $state['mode']);
        }
        if ($withDates || $state['mode'] === 'decade' || $state['query'] !== '') {
            $query->leftJoin('record_release_dates as dates', 'dates.discogs_release_id', '=', 'records.discogs_release_id');
        }

        // Keep q as free text instead of parsing commas and booleans as filter values.
        return QueryBuilder::for($query, new Request)
            ->allowedFilters(AllowedFilter::callback('search', $this->applySearch(...))
                ->default($state['query'])->delimiter(''))
            ->toBase();
    }

    /** @param EloquentBuilder<CollectionRecord> $query */
    protected function applySearch(EloquentBuilder $query, string $search): void
    {
        $terms = preg_split('/\s+/', Str::lower(Str::ascii(trim($search))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $text = DB::getDriverName() === 'pgsql' ? 'TEXT' : 'CHAR';
            $query->where(fn (EloquentBuilder $query) => $query
                ->whereRaw("records.browse_search LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw('CAST('.$this->yearExpression()." AS {$text}) LIKE ? ESCAPE '!'", [$pattern]));
        }
    }

    /** @return literal-string */
    protected function yearExpression(): string
    {
        return 'COALESCE(records.browse_original_year, CAST(SUBSTR(dates.original_release_date, 1, 4) AS DECIMAL))';
    }

    /** @return literal-string */
    protected function sectionExpression(): string
    {
        return match ($this->state()['mode']) {
            'artist', 'genre' => 'entries.name',
            'decade' => 'FLOOR('.$this->yearExpression().'/10)*10',
            default => 'records.browse_added_day',
        };
    }

    protected function sectionName(string|int|float|null $section): string
    {
        if ($this->state()['mode'] === 'decade') {
            return $section === null ? 'Unknown year' : (int) $section.'s';
        }

        return (string) $section;
    }

    /** @return list<array{copyId: int, section: string, key: string}> */
    protected function entries(int $offset, int $limit): array
    {
        $groups = array_values(array_filter($this->groups(), fn (array $group) => $group['offset'] < $offset + $limit && $offset < $group['offset'] + $group['count']));
        if ($groups === []) {
            return [];
        }

        $mode = $this->state()['mode'];
        $section = $this->sectionExpression();
        $values = array_map(fn (array $group) => $mode === 'decade' ? ($group['name'] === 'Unknown year' ? null : (int) $group['name']) : $group['name'], $groups);
        $query = $this->query(withDates: $mode === 'artist')->where(function (Builder $query) use ($section, $values): void {
            foreach ($values as $value) {
                $value === null ? $query->orWhereNull(DB::raw($section)) : $query->orWhereRaw("{$section} = ?", [$value]);
            }
        })->select(['records.id as copyId'])->selectRaw("{$section} AS section");
        $relativeOffset = $offset - $groups[0]['offset'];

        if ($mode === 'decade') {
            // MySQL otherwise prefers the user/instance index and reads every large record row.
            $query->when(DB::getDriverName() === 'mysql', fn (Builder $query) => $query->forceIndex('collection_records_decade_index'))
                ->where(function (Builder $query) use ($values): void {
                    $query->whereNull('records.browse_original_year');
                    foreach ($values as $value) {
                        if ($value !== null) {
                            $year = (int) $value;
                            $query->orWhereBetween('records.browse_original_year', [$year, $year + 9]);
                        }
                    }
                });
        }

        if (in_array($mode, ['recent', 'genre'], true)) {
            if ($mode === 'recent') {
                $query->orderByDesc('records.browse_added_day');
            }
            if ($mode === 'genre' && count($values) > 1) {
                $ordering = 'CASE';
                $bindings = [];
                foreach ($values as $position => $value) {
                    $ordering .= " WHEN {$section} = ? THEN ?";
                    array_push($bindings, $value, $position);
                }
                $query->orderByRaw("{$ordering} END", $bindings);
            }
            $rows = $query->orderByDesc('records.added_at')->orderByDesc('records.id')
                ->offset($relativeOffset)->limit($limit)->get()->all();

            return array_map(fn (object $row) => ['copyId' => (int) $row->copyId, 'section' => $this->sectionName($row->section), 'key' => $this->sectionName($row->section).':'.$row->copyId], array_values($rows));
        }

        // Keep PHP's natural title ordering, but read only narrow rows in the sections on this page.
        $text = DB::getDriverName() === 'pgsql' ? 'TEXT' : 'CHAR';
        $date = "CASE WHEN records.browse_original_year IS NOT NULL AND records.browse_original_year <> CAST(SUBSTR(dates.original_release_date, 1, 4) AS DECIMAL)
            THEN CAST(records.browse_original_year AS {$text}) ELSE COALESCE(dates.original_release_date, CAST(records.browse_original_year AS {$text})) END";
        $rows = $query->addSelect('records.browse_title as title')->selectRaw("{$date} AS original_date")->get()->all();
        $positions = array_flip(array_column($groups, 'name'));
        usort($rows, function ($first, $second) use ($positions): int {
            $groups = $positions[$this->sectionName($first->section)] <=> $positions[$this->sectionName($second->section)];
            $dates = ($second->original_date ?? '') <=> ($first->original_date ?? '');

            return $groups ?: $dates
                ?: strnatcasecmp($first->title, $second->title) ?: $first->copyId <=> $second->copyId;
        });

        return array_map(fn (object $row) => ['copyId' => (int) $row->copyId, 'section' => $this->sectionName($row->section), 'key' => $this->sectionName($row->section).':'.$row->copyId], array_slice($rows, $relativeOffset, $limit));
    }
}
