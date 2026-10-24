<?php

namespace App\Support;

use App\Models\Release;
use Illuminate\Support\Str;
use RuntimeException;

class MusicBrainzListeningLinks
{
    public function __construct(
        protected MusicBrainz $musicBrainz,
    ) {}

    /** @return array<string, mixed> */
    public function forRelease(Release $release): array
    {
        $record = ['id' => $release->id, ...$release->recordData()];
        $group = $this->group($record);
        if ($group === null) {
            return [];
        }

        $links = ['musicbrainzId' => $group];
        $tracks = array_map($this->comparable(...), array_column($record['tracks'] ?? [], 'title'));
        if ($tracks === []) {
            return $links;
        }

        $offset = 0;
        do {
            $page = $this->musicBrainz->get('release', ['release-group' => $group, 'inc' => 'url-rels+media+artist-credits', 'limit' => 100, 'offset' => $offset]);
            $editions = $page['releases'] ?? [];
            if ($editions === [] && $offset < ($page['release-count'] ?? 0)) {
                throw new RuntimeException('MusicBrainz returned an incomplete release list.');
            }

            foreach ($editions as $edition) {
                if (! $this->sameRecord($record, $edition) || ! $this->sameEdition($record, $edition)) {
                    continue;
                }
                $media = $edition['media'] ?? [];
                if ($media === [] || array_diff(array_column($media, 'format'), ['Digital Media']) !== []) {
                    continue;
                }
                if (array_sum(array_column($media, 'track-count')) !== count($tracks)) {
                    continue;
                }
                $candidates = $this->albumLinks($edition);
                if ($candidates === []) {
                    continue;
                }

                $details = $this->musicBrainz->get('release/'.$edition['id'], ['inc' => 'recordings']);
                $titles = [];
                foreach ($details['media'] ?? [] as $medium) {
                    foreach ($medium['tracks'] ?? [] as $track) {
                        $titles[] = $this->comparable($track['title'] ?? $track['recording']['title'] ?? '');
                    }
                }
                if ($titles !== $tracks) {
                    continue;
                }

                foreach ($candidates as $service => $url) {
                    if (! isset($links[$service.'Url']) || ($service === 'appleMusic' && str_starts_with($url, 'https://music.apple.com/be/'))) {
                        $links[$service.'Url'] = $url;
                        $links[$service.'Search'] = false;
                    }
                }
            }

            $offset += count($editions);
        } while ($offset < ($page['release-count'] ?? 0));

        return $links;
    }

    /** @param array<string, mixed> $record */
    protected function group(array $record): ?string
    {
        if (! empty($record['musicbrainzId'])) {
            $group = $this->musicBrainz->get('release-group/'.$record['musicbrainzId'], ['inc' => 'artist-credits']);
            if ($group !== null && $this->sameRecord($record, $group)) {
                return $group['id'];
            }
        }

        foreach (['release' => $record['id'], 'master' => $record['discogsMasterId'] ?? null] as $kind => $id) {
            if (! $id) {
                continue;
            }
            $mapping = $this->musicBrainz->get('url', [
                'resource' => "https://www.discogs.com/{$kind}/{$id}",
                'inc' => 'release-rels+release-group-rels',
            ]);
            $groups = [];
            foreach ($mapping['relations'] ?? [] as $relation) {
                if (isset($relation['release'])) {
                    $edition = $this->musicBrainz->get('release/'.$relation['release']['id'], ['inc' => 'release-groups+artist-credits']);
                    if ($edition !== null && $this->sameRecord($record, $edition) && isset($edition['release-group']['id'])) {
                        $groups[] = $edition['release-group']['id'];
                    }
                }
                if (isset($relation['release-group'])) {
                    $group = $this->musicBrainz->get('release-group/'.$relation['release-group']['id'], ['inc' => 'artist-credits']);
                    if ($group !== null && $this->sameRecord($record, $group)) {
                        $groups[] = $group['id'];
                    }
                }
            }
            $groups = array_values(array_unique($groups));
            if (count($groups) === 1) {
                return $groups[0];
            }
        }

        $escape = fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $title = $escape($record['displayTitle'] ?? $record['title']);
        $artist = $escape($record['artists'][0] ?? $record['artist']);
        $result = $this->musicBrainz->get('release-group', ['query' => "releasegroup:\"{$title}\" AND artist:\"{$artist}\"", 'limit' => 25]);
        $matches = [];
        foreach ($result['release-groups'] ?? [] as $group) {
            if (($group['score'] ?? 0) < 95 || ! $this->sameRecord($record, $group)) {
                continue;
            }
            if (! empty($record['originalYear']) && (int) substr($group['first-release-date'] ?? '', 0, 4) !== $record['originalYear']) {
                continue;
            }
            if (preg_match('/\b(Album|Single|EP)\b/i', $record['edition'] ?? '', $type)) {
                if (strcasecmp($type[1], $group['primary-type'] ?? '') !== 0) {
                    continue;
                }
            }
            $matches[] = $group['id'];
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $candidate
     */
    protected function sameRecord(array $record, array $candidate): bool
    {
        $artists = array_map(fn (array $credit): string => $this->comparable($credit['artist']['name']), $candidate['artist-credit'] ?? []);
        $expected = array_map($this->comparable(...), $record['artists'] ?? [$record['artist']]);
        sort($artists);
        sort($expected);

        return $artists === $expected && in_array($this->comparable($candidate['title']), [
            $this->comparable($record['title']), $this->comparable($record['displayTitle'] ?? $record['title']),
        ], true);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $edition
     */
    protected function sameEdition(array $record, array $edition): bool
    {
        preg_match_all('/\b(deluxe|anniversary|expanded|remix|live|bonus)\b/i', ($record['title'] ?? '').' '.($record['edition'] ?? ''), $expected);
        preg_match_all('/\b(deluxe|anniversary|expanded|remix|live|bonus)\b/i', ($edition['title'] ?? '').' '.($edition['disambiguation'] ?? ''), $actual);

        return array_diff(array_map('strtolower', $actual[0]), array_map('strtolower', $expected[0])) === [];
    }

    /**
     * @param  array<string, mixed>  $edition
     * @return array<string, string>
     */
    protected function albumLinks(array $edition): array
    {
        $links = [];
        foreach ($edition['relations'] ?? [] as $relation) {
            if ($relation['ended'] ?? false) {
                continue;
            }
            $url = $relation['url']['resource'] ?? '';
            $parts = parse_url($url);
            if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
                continue;
            }
            $host = strtolower($parts['host'] ?? '');
            $path = $parts['path'] ?? '';
            $service = match (true) {
                $host === 'open.spotify.com' && str_starts_with($path, '/album/') => 'spotify',
                $host === 'music.apple.com' && preg_match('#^/[a-z]{2}/album/#', $path) === 1 => 'appleMusic',
                str_ends_with($host, '.bandcamp.com') && str_starts_with($path, '/album/') => 'bandcamp',
                default => null,
            };
            if ($service !== null && (! isset($links[$service]) || ($service === 'appleMusic' && str_starts_with($path, '/be/')))) {
                $links[$service] = $url;
            }
        }

        return $links;
    }

    protected function comparable(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value))) ?? '';
    }
}
