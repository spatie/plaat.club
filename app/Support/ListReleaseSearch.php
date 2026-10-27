<?php

namespace App\Support;

use App\Models\Release;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ListReleaseSearch
{
    /** @return array<string, mixed> */
    public function search(Request $request, User $owner): array
    {
        $input = $request->query('q', '');
        $query = is_string($input) ? trim(mb_substr($input, 0, 200)) : '';
        $page = max(1, min(100, $request->integer('recordsPage', 1)));
        $result = ['query' => $query, 'source' => 'discogs', 'searchError' => null,
            'results' => ['data' => [], 'prev_page_url' => null, 'next_page_url' => null]];
        if (mb_strlen($query) < 3) {
            return $result;
        }

        $releaseId = null;
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*://|www\.)#i', $query)) {
            if (! preg_match('#^https://(?:www\.)?discogs\.com/(?:[a-z]{2}(?:-[A-Z]{2})?/)?release/([1-9][0-9]{0,9})(?:-[^/?\s]*)?/?(?:\?[^\s]*)?$#', $query, $matches)) {
                return [...$result, 'searchError' => 'Paste a Discogs release URL, not an artist, master or another website.'];
            }
            $releaseId = (int) $matches[1];
        }
        $parameters = ['type' => 'release', 'q' => $query, 'page' => $page, 'per_page' => 36];

        try {
            if ($releaseId !== null) {
                $release = $this->release($owner, $releaseId);

                return $release === null
                    ? [...$result, 'searchError' => 'Discogs did not return enough information for this release.']
                    : [...$result, 'results' => [...$result['results'], 'data' => [$release]]];
            }
            $response = Cache::remember('list-search:'.$owner->id.':'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR)), now()->addMinutes(5),
                fn () => (new Discogs(filled($owner->discogs_token) ? $owner : null))->get('database/search', $parameters));
        } catch (DiscogsRateLimitException|ConnectionException|RequestException) {
            return [...$result, 'searchError' => 'Discogs search is unavailable or busy. Please try again shortly.'];
        }

        $ownedReleaseIds = (new ProfileCollection($owner, new Request(['q' => $query])))->matchingReleaseIds();
        $listRecords = app(ListRecords::class);
        $result['results']['data'] = array_values(array_map(function (array $record) use ($owner, $listRecords, $request): array {
            $parts = explode(' - ', $record['title'] ?? '', 2);

            return [
                'releaseId' => (int) $record['id'], 'artist' => app(DiscogsRecordMapper::class)->artistName($parts[0]), 'displayTitle' => $parts[1] ?? $parts[0],
                'year' => (int) ($record['year'] ?? 0), 'originalYear' => null,
                ...(isset($record['country']) ? ['country' => $record['country']] : []),
                ...(! empty($record['label']) ? ['label' => $record['label'][0]] : []),
                'catalogNumber' => $record['catno'] ?? '',
                'discogsUrl' => 'https://www.discogs.com/release/'.(int) $record['id'],
                'format' => implode(', ', $record['format'] ?? []),
                'mediaFormats' => app(DiscogsRecordMapper::class)->mediaFormats(implode(', ', $record['format'] ?? [])),
                'cover' => ($record['cover_image'] ?? '') ?: (($record['thumb'] ?? '') ?: route('lists.releaseCover', [$request->route('recordList'), $record['id']], false)),
                ...$listRecords->ownership($owner, (int) $record['id'], empty($record['master_id']) ? null : (int) $record['master_id']),
            ];
        }, array_filter($response['results'] ?? [], fn (array $record) => ($record['type'] ?? 'release') === 'release'
            && ! empty($record['id']) && ! in_array((int) $record['id'], $ownedReleaseIds, true))));
        if ($page === 1) {
            $ownedReleases = Release::query()->whereIn('id', $ownedReleaseIds)->orderBy('id')->get()
                ->map(fn (Release $release) => $this->summary($owner, $release->id, $release->recordData()))->all();
            $result['results']['data'] = [...$ownedReleases, ...$result['results']['data']];
        }
        $result['results']['prev_page_url'] = $page > 1 ? $request->fullUrlWithQuery(['recordsPage' => $page - 1]) : null;
        $result['results']['next_page_url'] = $page < ($response['pagination']['pages'] ?? 1) ? $request->fullUrlWithQuery(['recordsPage' => $page + 1]) : null;

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function release(User $owner, int $releaseId): ?array
    {
        $release = Release::query()->find($releaseId);
        $payload = $release?->recordData();
        if ($payload === null) {
            $details = (new Discogs(filled($owner->discogs_token) ? $owner : null))->get("releases/{$releaseId}");
            if (empty($details['title']) || empty($details['artists'])) {
                return null;
            }
            $payload = app(DiscogsRecordMapper::class)->record([
                'instance_id' => 0, 'date_added' => now()->toIso8601String(),
                'basic_information' => [...$details, 'id' => $releaseId, 'cover_image' => $details['cover_image'] ?? $details['thumb'] ?? ''],
            ], $details, []);
            $payload['cover'] = $payload['coverSource'];
        }

        return $this->summary($owner, $releaseId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function summary(User $owner, int $releaseId, array $payload): array
    {
        return [
            ...Arr::only($payload, ['artist', 'displayTitle', 'year', 'originalYear', 'format', 'mediaFormats', 'cover', 'country', 'label', 'catalogNumber', 'discogsUrl']),
            'releaseId' => $releaseId,
            ...app(ListRecords::class)->ownership($owner, $releaseId, $payload['discogsMasterId'] ?? null),
        ];
    }
}
