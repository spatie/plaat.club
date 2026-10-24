<?php

namespace App\Actions\Collections;

use App\Models\CollectionRecord;
use App\Models\User;
use App\Support\SocialData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use stdClass;

class SearchCollectionsAction
{
    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function execute(User $viewer, User $owner, array $plan): array
    {
        $owner = User::query()->findOrFail($owner->id);
        abort_unless($owner->canViewCollection($viewer), 403);
        $semantic = $plan['semantic_query'] ?? null;
        if ($semantic !== null) {
            if (! config('services.openai.semantic_enabled') || DB::getDriverName() !== 'pgsql') {
                return $this->empty('Metadata-based recommendations require an enabled PostgreSQL search index.');
            }
            if ($plan['intent'] !== 'search') {
                return $this->empty('Recommendations cannot select records for list changes or comparisons.');
            }
        }
        $target = null;
        if ($plan['target_handle'] !== null) {
            $reference = trim($plan['target_handle']);
            $name = Str::lower(ltrim($reference, '@'));
            $candidates = User::query()->where(function (Builder $query) use ($reference, $name): void {
                $query->where('handle', $name);
                if (! str_starts_with($reference, '@')) {
                    $query->orWhereRaw('LOWER(name) = ?', [$name]);
                }
            })->get()->filter(fn (User $user): bool => $user->canViewCollection($viewer));
            if ($candidates->isEmpty() && ! str_starts_with($reference, '@') && mb_strlen($name) >= 3) {
                $friends = $viewer->friends()->get()->filter(fn (User $user): bool => $user->canViewCollection($viewer));
                $candidates = $friends->filter(fn (User $user): bool => collect(explode(' ', $this->normalize($user->name)))
                    ->push($user->handle)->contains(fn (string $part): bool => str_starts_with($part, $this->normalize($name))));
                if ($candidates->isEmpty() && mb_strlen($name) >= 4) {
                    $candidates = $friends->filter(fn (User $user): bool => collect(explode(' ', $this->normalize($user->name)))
                        ->push($user->handle)->contains(fn (string $part): bool => levenshtein($part, $this->normalize($name)) <= 1));
                }
            }
            if ($candidates->count() > 1) {
                return $this->empty('More than one visible collector matches that name: '.$candidates->map(fn (User $user): string => '@'.$user->handle)->implode(', ').'. Please use their unique username.');
            }
            $target = $candidates->first();
            if ($target === null) {
                return $this->empty('That collection is unavailable. You can only search or compare collections you can currently view.');
            }
        }

        if ($plan['intent'] === 'compare') {
            if ($target === null) {
                return $this->empty('Who would you like to compare with? Include their username or full name.');
            }

            return $this->compare($viewer, $target);
        }

        $searchFriends = $target === null && $plan['scope'] === 'friends';
        $scope = CollectionRecord::visibleTo($viewer)->whereIn('user_id', $searchFriends
            ? $viewer->friends()->select('id')
            : [($target ?? $owner)->id]);

        $query = $this->releases($scope);
        if ($plan['year_from'] !== null) {
            $query->where('original_year', '>=', $plan['year_from']);
        }
        if ($plan['year_to'] !== null) {
            $query->where('original_year', '<=', $plan['year_to']);
        }
        $fields = ['genres', 'styles', 'artists', 'mediaFormats'];
        foreach (['role' => 'credits', 'track' => 'tracks', 'label' => 'labels'] as $filter => $field) {
            if ($plan[$filter] !== null) {
                $fields[] = $field;
            }
        }
        $rows = $this->metadata($query, $fields);
        $matches = [];
        foreach ($rows as $release) {
            $evidence = $this->match($release, $plan);
            if ($evidence !== null) {
                $matches[$release->id] = $evidence;
            }
        }
        if ($semantic !== null) {
            $matches = $this->suggestions($matches, $rows, $semantic);
        }
        $extreme = $plan['year_extreme'] ?? null;
        $year = null;
        if ($extreme !== null) {
            $dated = $rows->whereIn('id', array_keys($matches))->filter(fn (stdClass $release): bool => $release->original_year > 0);
            $year = $extreme === 'oldest' ? $dated->min('original_year') : $dated->max('original_year');
            $matches = array_intersect_key($matches, array_flip($dated->where('original_year', $year)->pluck('id')->all()));
        }
        $recentlyAdded = ($plan['order'] ?? null) === 'recently_added';
        if ($recentlyAdded) {
            $recentIds = (clone $scope)->whereIn('discogs_release_id', array_keys($matches))
                ->select('discogs_release_id')->groupBy('discogs_release_id')
                ->orderByRaw('MAX(added_at) DESC')->orderByDesc('discogs_release_id')->pluck('discogs_release_id');
            $matches = $recentIds->mapWithKeys(fn (int $id): array => [$id => $matches[$id]])->all();
        }
        $ids = array_slice(array_keys($matches), 0, 24);
        $copies = (clone $scope)->whereIn('discogs_release_id', $ids)->with('user')->get()->unique('discogs_release_id')->keyBy('discogs_release_id');
        $records = [];
        foreach ($ids as $id) {
            if ($copy = $copies->get($id)) {
                $records[] = $this->record($copy, $matches[$id]);
            }
        }
        $collectionName = ($target ?? $owner)->is($viewer) ? 'your collection' : '@'.($target ?? $owner)->handle;
        $collectionName = $searchFriends ? 'your friends’ visible collections' : $collectionName;
        $message = 'I found '.count($matches).($semantic === null ? ' matching releases in ' : ' suggested releases from ').$collectionName.'.';
        if ($semantic !== null) {
            $message .= ' Suggestions use album metadata, not audio analysis.';
        }
        if ($extreme !== null) {
            $message = $year === null
                ? 'I could not find matching releases with a known original release year in '.$collectionName.'.'
                : 'The '.$extreme.' matching '.(count($matches) === 1 ? 'release in ' : 'releases in ').$collectionName.' '.(count($matches) === 1 ? 'is' : 'are').' from '.$year.'. This uses original release years, not the age of your pressing; unknown years are excluded.';
        }
        if ($recentlyAdded) {
            $message = $records === [] ? 'No matching records found in '.$collectionName.'.'
                : 'Here are the '.count($records).' most recently added releases in '.$collectionName.', ordered by collection addition date.';
        }
        if ($matches === [] && $plan['person'] !== null && $plan['role'] !== null && $semantic === null) {
            $otherCredits = [];
            foreach ($rows as $release) {
                if ($this->match($release, [...$plan, 'person' => null, 'role' => null]) === null) {
                    continue;
                }
                $credits = $release->credits['release'] ?? [];
                foreach ($release->credits['tracks'] ?? [] as $track) {
                    array_push($credits, ...($track['artists'] ?? []));
                }
                foreach ($credits as $credit) {
                    if ($this->normalize($credit['name']) === $this->normalize($plan['person'])) {
                        $otherCredits[] = $release->title.' ('.$credit['role'].')';
                    }
                }
            }
            $message = 'I found no '.$plan['role'].' credits for '.$plan['person'].' in the available metadata for '.$collectionName.'.';
            if ($otherCredits !== []) {
                $message .= ' I did find other credits: '.implode('; ', array_slice(array_unique($otherCredits), 0, 4)).'. Those are not '.$plan['role'].' matches.';
            }
        }
        if (($plan['release_type'] ?? null) !== null) {
            $message .= ' Matches use stored release-type tags; untagged releases may be missing.';
        }

        return [
            'message' => $message,
            'total' => count($matches), 'records' => $records, 'comparison' => null,
            'copyIds' => $semantic === null ? (clone $scope)->whereIn('discogs_release_id', array_keys($matches))->orderBy('id')->pluck('id')->all() : [],
            'collectors' => ($searchFriends ? $copies->pluck('user')->unique('id') : collect([$target ?? $owner]))
                ->map(fn (User $user): array => $this->collector($user))->values()->all(),
            'filters' => $this->filters($plan),
            'notice' => $semantic === null
                ? 'Matches use stored Discogs metadata, which can be incomplete. Different pressings may appear separately. Showing up to 24 releases.'
                : 'Suggestions use the last indexed public album titles, artists, genres and styles, not audio analysis. Mood and similarity are not verified facts. Only indexed releases are considered; showing up to 24 albums.',
        ];
    }

    /** @param array<int, list<string>> $matches
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, list<string>>
     */
    protected function suggestions(array $matches, Collection $rows, string $question): array
    {
        if ($matches === []) {
            return [];
        }

        $vector = Embeddings::for([$question])->dimensions(512)->cache()->generate('openai', 'text-embedding-3-small')->first();
        $ranked = DB::table('release_embeddings')->whereIn('release_id', array_keys($matches))
            ->orderByVectorDistance('embedding', $vector)->orderBy('release_id')->pluck('release_id');
        $releases = $rows->keyBy('id');
        $albums = [];
        $suggestions = [];
        foreach ($ranked as $id) {
            $release = $releases[$id];
            $album = $release->discogs_master_id ?? $this->albumTitle($release);
            if (isset($albums[$album])) {
                continue;
            }
            $albums[$album] = true;
            $suggestions[$id] = [...$matches[$id], 'Metadata-based suggestion'];
            if (count($suggestions) === 24) {
                break;
            }
        }

        return $suggestions;
    }

    /** @param Builder<CollectionRecord> $scope */
    protected function releases(Builder $scope): QueryBuilder
    {
        return DB::table('releases')->whereIn('id', (clone $scope)->select('discogs_release_id')->toBase())
            ->select('id', 'artist', 'title', 'original_year', 'discogs_master_id', 'label', 'edition')->orderBy('artist')->orderBy('title')->orderBy('id');
    }

    /** @param list<literal-string> $fields
     * @return Collection<int, stdClass>
     */
    protected function metadata(QueryBuilder $query, array $fields): Collection
    {
        foreach ($fields as $field) {
            $query->addSelect("data->{$field} as {$field}");
        }

        return $query->get()->map(function (stdClass $release) use ($fields): stdClass {
            foreach ($fields as $field) {
                $release->{$field} = json_decode($release->{$field} ?? '[]', true) ?? [];
            }

            return $release;
        });
    }

    /** @param array<string, mixed> $plan
     * @return list<string>|null
     */
    protected function match(stdClass $release, array $plan): ?array
    {
        $evidence = [];
        if (($plan['release_type'] ?? null) !== null) {
            $types = $plan['release_type'] === 'Single' ? ['Single', 'Maxi-Single'] : [$plan['release_type']];
            if (array_intersect(array_map($this->normalize(...), $types), array_map(fn (string $tag): string => $this->normalize(trim($tag)), explode(',', $release->edition ?? ''))) === []) {
                return null;
            }
            $evidence[] = 'Release type: '.$plan['release_type'];
        }
        if ($plan['person'] !== null && $plan['role'] === null) {
            if (! in_array($this->normalize($plan['person']), array_map($this->normalize(...), $release->artists), true)) {
                return null;
            }
            $evidence[] = 'Artist: '.$release->artist;
        }
        if ($plan['exclude_artist'] !== null && in_array($this->normalize($plan['exclude_artist']), array_map($this->normalize(...), $release->artists), true)) {
            return null;
        }
        foreach (['genre' => 'genres', 'style' => 'styles'] as $filter => $field) {
            if ($plan[$filter] !== null) {
                if (! in_array($this->normalize($plan[$filter]['value']), array_map($this->normalize(...), $release->{$field}), true)) {
                    return null;
                }
                $evidence[] = ucfirst($filter).': '.$plan[$filter]['value'];
            }
        }
        if ($plan['format'] !== null) {
            if (! in_array($plan['format'], $release->mediaFormats, true)) {
                return null;
            }
            $evidence[] = $plan['format'];
        }
        if ($plan['title'] !== null) {
            if (! str_contains($this->normalize($release->title), $this->normalize($plan['title']))) {
                return null;
            }
            $evidence[] = 'Title: '.$release->title;
        }
        if ($plan['label'] !== null) {
            $labels = array_column($release->labels, 'name');
            $labels[] = $release->label;
            $matching = array_values(array_filter($labels, fn (string $name): bool => str_contains($this->normalize($name), $this->normalize($plan['label']))));
            if ($matching === []) {
                return null;
            }
            $evidence[] = 'Label: '.$matching[0];
        }
        if ($plan['track'] !== null) {
            $matching = array_values(array_filter($release->tracks, fn (array $track): bool => str_contains($this->normalize($track['title']), $this->normalize($plan['track']))));
            if ($matching === []) {
                return null;
            }
            $evidence[] = 'Track: '.$matching[0]['title'];
        }
        if ($plan['role'] !== null) {
            $credits = $release->credits['release'] ?? [];
            foreach ($release->credits['tracks'] ?? [] as $track) {
                array_push($credits, ...($track['artists'] ?? []));
            }
            $matching = array_values(array_filter($credits, function (array $credit) use ($plan): bool {
                if ($plan['person'] !== null && $this->normalize($credit['name']) !== $this->normalize($plan['person'])) {
                    return false;
                }

                if ($plan['role'] === 'Any') {
                    return true;
                }

                $role = preg_quote($plan['role'], '/');
                if ($plan['role'] === 'Bass') {
                    $role .= '(?: Guitar)?';
                }
                $prefix = $plan['role'] === 'Producer' ? '(?:Co-)?' : '(?:[^,]+\s)?';
                $roles = preg_replace('/\[[^\]]*\]/', '', $credit['role'] ?? '');

                return preg_match('/(?:^|,)\s*'.$prefix.$role.'\s*(?:,|$)/i', $roles) === 1;
            }));
            if ($matching === []) {
                return null;
            }
            foreach ($matching as $credit) {
                $evidence[] = $credit['name'].' — '.$credit['role'];
            }
        }
        if ($plan['year_from'] !== null || $plan['year_to'] !== null) {
            $evidence[] = 'Original year: '.$release->original_year;
        }

        return $evidence;
    }

    /** @return array<string, mixed> */
    protected function compare(User $viewer, User $target): array
    {
        $mine = $this->metadata($this->releases($viewer->records()->getQuery()), ['artists', 'styles']);
        $theirs = $this->metadata($this->releases(CollectionRecord::visibleTo($viewer)->where('user_id', $target->id)), ['artists', 'styles']);
        $byMaster = $theirs->filter(fn (stdClass $release): bool => $release->discogs_master_id !== null)->groupBy('discogs_master_id');
        $byTitle = $theirs->groupBy(fn (stdClass $release): string => $this->albumTitle($release));
        $shared = [];
        foreach ($mine as $release) {
            $other = $release->discogs_master_id ? $byMaster->get($release->discogs_master_id)?->first() : null;
            $exactMaster = $other !== null;
            $other ??= $byTitle->get($this->albumTitle($release))?->first(fn (stdClass $candidate): bool => ! $release->discogs_master_id || ! $candidate->discogs_master_id);
            if ($other === null) {
                continue;
            }
            $key = $release->discogs_master_id ?? $other->discogs_master_id ?? $this->albumTitle($release);
            $shared[$key] ??= ['id' => $release->id, 'evidence' => [$exactMaster ? 'Same Discogs master; pressing may differ.' : 'Same artist and title; master ID unavailable, album match is unverified.']];
        }
        $copies = $viewer->records()->whereIn('discogs_release_id', array_column(array_slice($shared, 0, 24), 'id'))->with('user')->get()->keyBy('discogs_release_id');
        $records = [];
        foreach (array_slice($shared, 0, 24) as $match) {
            if ($copy = $copies->get($match['id'])) {
                $records[] = $this->record($copy, $match['evidence']);
            }
        }
        $artists = $this->commonTerms($mine, $theirs, 'artists');
        $styles = $this->commonTerms($mine, $theirs, 'styles');

        return [
            'message' => 'You and @'.$target->handle.' have '.count($shared).' matching record titles, and both collect '.count($artists).' main artists. Different pressings of the same release count together; matches without a Discogs master are unverified.',
            'total' => count($shared), 'records' => $records,
            'collectors' => [$this->collector($viewer), $this->collector($target)],
            'filters' => ['Compare: your collection ↔ @'.$target->handle],
            'comparison' => ['handle' => $target->handle, 'albums' => count($shared), 'artists' => array_slice($artists, 0, 12), 'styles' => array_slice($styles, 0, 8), 'artistCount' => count($artists)],
            'notice' => 'Matching records use Discogs masters where available; artist/title fallbacks are labelled unverified. Artist and style counts are distinct releases in each collection, not shared titles, listening habits or a taste score.',
        ];
    }

    /** @param Collection<int, stdClass> $mine
     * @param  Collection<int, stdClass>  $theirs
     * @return list<array{name: string, mine: int, theirs: int}>
     */
    protected function commonTerms(Collection $mine, Collection $theirs, string $field): array
    {
        $counts = [];
        foreach (['mine' => $mine, 'theirs' => $theirs] as $side => $releases) {
            foreach ($releases as $release) {
                foreach (array_unique($release->{$field}) as $name) {
                    $key = $this->normalize($name);
                    $counts[$key] ??= ['name' => $name, 'mine' => 0, 'theirs' => 0];
                    $counts[$key][$side]++;
                }
            }
        }
        $common = array_values(array_filter($counts, fn (array $term): bool => $term['mine'] > 0 && $term['theirs'] > 0));
        usort($common, fn (array $first, array $second): int => min($second['mine'], $second['theirs']) <=> min($first['mine'], $first['theirs']) ?: strcmp($first['name'], $second['name']));

        return $common;
    }

    protected function albumTitle(stdClass $release): string
    {
        return $this->normalize($release->artist).'|'.$this->normalize($release->title);
    }

    /** @param list<string> $evidence
     * @return array<string, mixed>
     */
    protected function record(CollectionRecord $copy, array $evidence): array
    {
        return [
            'title' => $copy->payload['title'], 'artist' => $copy->payload['artist'], 'year' => $copy->payload['originalYear'],
            'recordId' => $copy->id,
            'url' => $copy->url(false), 'owner' => $copy->user->handle,
            'mediaFormats' => $copy->formats(),
            'cover' => route('shelf.cover', $copy, false), 'evidence' => $evidence,
        ];
    }

    /** @return array<string, mixed> */
    protected function collector(User $user): array
    {
        return [
            ...SocialData::person($user),
            'count' => $user->records()->count(),
            'covers' => $user->records()->without('release')->latest('added_at')->limit(4)->get(['id', 'public_id'])
                ->map(fn (CollectionRecord $copy): string => route('shelf.cover', $copy, false))->all(),
        ];
    }

    /** @param array<string, mixed> $plan
     * @return list<string>
     */
    protected function filters(array $plan): array
    {
        $filters = [];
        foreach (['person', 'role', 'exclude_artist', 'title', 'label', 'track', 'format', 'year_from', 'year_to'] as $field) {
            if ($plan[$field] !== null) {
                $filters[] = ucfirst(str_replace('_', ' ', $field)).': '.$plan[$field];
            }
        }
        foreach (['genre', 'style'] as $field) {
            if ($plan[$field] !== null) {
                $filters[] = ucfirst($field).': '.$plan[$field]['value'];
            }
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    protected function empty(string $message): array
    {
        return ['message' => $message, 'total' => 0, 'records' => [], 'comparison' => null, 'filters' => [], 'notice' => ''];
    }

    protected function normalize(string $value): string
    {
        return Str::lower(Str::ascii(preg_replace('/ \([0-9]+\)$/', '', $value) ?? $value));
    }
}
