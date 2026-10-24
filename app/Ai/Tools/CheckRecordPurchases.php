<?php

namespace App\Ai\Tools;

use App\Ai\ToolResult;
use App\Models\Release;
use App\Models\User;
use App\Support\MusicBrainz;
use App\Support\MusicBrainzRateLimitException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CheckRecordPurchases implements Tool
{
    protected int $lookups = 0;

    public function __construct(
        protected User $viewer,
    ) {}

    public function description(): string
    {
        return 'Verify up to five proposed purchases against already imported public catalogue facts, or MusicBrainz core metadata, and your full current collection. Only recommend status not_in_collection. owned, unverified and unavailable must not be described as missing purchases. Maximum eight candidate checks per turn. Sends candidate artist/title only, never your collection, to MusicBrainz. Does not check prices, stock, reviews or physical editions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['candidates' => $schema->array()->items($schema->object([
            'artist' => $schema->string()->required(),
            'title' => $schema->string()->required(),
        ])->withoutAdditionalProperties())->min(1)->max(5)->required()];
    }

    public function handle(Request $request): string
    {
        try {
            $data = $request->validate([
                'candidates' => ['required', 'array', 'min:1', 'max:5'],
                'candidates.*' => ['required', 'array:artist,title'],
                'candidates.*.artist' => ['required', 'string', 'max:200'],
                'candidates.*.title' => ['required', 'string', 'max:200'],
            ]);
        } catch (ValidationException $exception) {
            return ToolResult::encode(['error' => implode("\n", $exception->validator->errors()->all())]);
        }

        $catalogue = Release::query()->without('artists')->get([
            'id', 'artist', 'title', 'display_title', 'original_year', 'musicbrainz_id', 'discogs_master_id',
        ]);
        $ownedIds = $this->viewer->records()->without('release')->pluck('discogs_release_id');
        $ownedReleases = $catalogue->whereIn('id', $ownedIds);
        $results = [];

        foreach ($data['candidates'] as $candidate) {
            if (++$this->lookups > 8) {
                $results[] = [
                    ...$candidate,
                    'status' => 'unavailable',
                    'reason' => 'Candidate check limit reached for this turn.',
                ];

                continue;
            }

            try {
                $album = $this->findAlbum($catalogue, $candidate['artist'], $candidate['title']);
            } catch (ConnectionException|RequestException|MusicBrainzRateLimitException|LockTimeoutException) {
                $results[] = [
                    ...$candidate,
                    'status' => 'unavailable',
                    'reason' => 'MusicBrainz is temporarily unavailable; ownership and year are not verified.',
                ];

                continue;
            }

            if ($album === null) {
                $results[] = [
                    ...$candidate,
                    'status' => 'unverified',
                    'reason' => 'No unique exact artist and album match in MusicBrainz.',
                ];

                continue;
            }

            $results[] = [
                ...$album,
                'status' => $this->ownsAlbum($ownedReleases, $album) ? 'owned' : 'not_in_collection',
            ];
        }

        return ToolResult::encode([
            'candidates' => $results,
            'limitations' => 'Ownership checks use imported collection data, album IDs and normalised artist/title including common edition suffixes; unmatched aliases can remain. Not a review, audio analysis, price or stock check. Explain musical fit as your judgment, not a MusicBrainz opinion. Never describe results as the latest releases: catalogue coverage and model knowledge may be incomplete.',
        ]);
    }

    /**
     * @param  Collection<int, Release>  $catalogue
     * @return array<string, mixed>|null
     */
    protected function findAlbum(Collection $catalogue, string $artist, string $title): ?array
    {
        $normalizedArtist = $this->comparable($artist);
        $normalizedTitle = $this->albumTitle($title);
        $matches = $catalogue->filter(fn (Release $release): bool => $this->comparable($release->artist ?? '') === $normalizedArtist
            && $this->albumTitle($release->title ?? '') === $normalizedTitle);

        if ($matches->pluck('original_year')->unique()->count() === 1) {
            $release = $matches->first();

            return [
                'id' => $release->musicbrainz_id,
                'masterId' => $release->discogs_master_id,
                'artist' => $release->artist,
                'title' => $title,
                'year' => $release->original_year,
                'source' => 'Imported Discogs catalogue metadata',
                'url' => "https://www.discogs.com/release/{$release->id}",
            ];
        }

        $group = $this->findGroup($artist, $title);

        if ($group === null) {
            return null;
        }

        return [
            ...$group,
            'source' => 'MusicBrainz core metadata',
            'url' => "https://musicbrainz.org/release-group/{$group['id']}",
        ];
    }

    /**
     * @param  Collection<int, Release>  $ownedReleases
     * @param  array<string, mixed>  $album
     */
    protected function ownsAlbum(Collection $ownedReleases, array $album): bool
    {
        $normalizedArtist = $this->comparable($album['artist']);
        $normalizedTitle = $this->albumTitle($album['title']);

        return $ownedReleases->contains(function (Release $release) use ($album, $normalizedArtist, $normalizedTitle): bool {
            if (filled($album['id']) && $release->musicbrainz_id === $album['id']) {
                return true;
            }

            if (filled($album['masterId'] ?? null) && $release->discogs_master_id === $album['masterId']) {
                return true;
            }

            if ($this->comparable($release->artist ?? '') !== $normalizedArtist) {
                return false;
            }

            return in_array($normalizedTitle, [
                $this->albumTitle($release->title ?? ''),
                $this->albumTitle($release->display_title ?? ''),
            ], true);
        });
    }

    /** @return array{id: string, artist: string, title: string, year: ?int}|null */
    protected function findGroup(string $artist, string $title): ?array
    {
        return Cache::remember('purchase-album:'.hash('sha256', json_encode([$artist, $title], JSON_THROW_ON_ERROR)), now()->addDays(7), function () use ($artist, $title): ?array {
            $escape = fn (string $value): string => preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $value) ?? '';
            $response = app(MusicBrainz::class)->get('release-group', [
                'query' => 'releasegroup:"'.$escape($title).'" AND artist:"'.$escape($artist).'" AND primarytype:album',
                'limit' => 25,
            ]);
            $matches = [];
            foreach ($response['release-groups'] ?? [] as $group) {
                if (! Str::isUuid($group['id'] ?? '') || ($group['primary-type'] ?? '') !== 'Album') {
                    continue;
                }
                $credit = implode('', array_map(fn (array $credit): string => ($credit['artist']['name'] ?? '').($credit['joinphrase'] ?? ''), $group['artist-credit'] ?? []));
                if ($this->comparable($title) !== $this->comparable($group['title'] ?? '') || $this->comparable($artist) !== $this->comparable($credit)) {
                    continue;
                }
                $date = $group['first-release-date'] ?? '';
                $matches[$group['id']] = [
                    'id' => $group['id'], 'artist' => $credit, 'title' => $group['title'],
                    'year' => preg_match('/^\d{4}(?:-|$)/', $date) ? (int) substr($date, 0, 4) : null,
                ];
            }

            return count($matches) === 1 ? array_values($matches)[0] : null;
        });
    }

    protected function albumTitle(string $value): string
    {
        $value = preg_replace('/\s*[\[(](?:[^\])]*(?:deluxe|expanded|anniversary|remaster|bonus)[^\])]*)[\])]/i', '', $value) ?? $value;

        return $this->comparable($value);
    }

    protected function comparable(string $value): string
    {
        $value = preg_replace('/\s+\(\d+\)$/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value))) ?? '';
    }
}
