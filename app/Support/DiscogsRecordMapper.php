<?php

namespace App\Support;

class DiscogsRecordMapper
{
    public string $placeholderCover = '/images/record-placeholder.svg';

    /** @var array<string, list<string>> */
    protected array $mediaNames = [
        'Vinyl' => ['Vinyl', 'Acetate', 'Flexi-disc', 'Lathe Cut', 'Shellac'],
        'CD' => ['CD', 'CDr', 'CD-R', 'CD-RW', 'SACD', 'CDV'],
        'Cassette' => ['Cassette', 'DCC', 'DAT', 'Microcassette', 'NT Cassette', 'DC-International', 'Elcaset'],
    ];

    public function __construct(
        protected DiscogsTracklist $tracklist,
    ) {}

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $master
     * @param  list<array<string, mixed>>  $artistBios
     * @return array<string, mixed>
     */
    public function record(array $entry, array $release, array $master, array $artistBios = []): array
    {
        $basic = $entry['basic_information'];
        $release['master_id'] ??= $basic['master_id'] ?? 0;
        $release['labels'] ??= $basic['labels'] ?? [];
        $artists = array_map(fn (array $artist): string => $this->artistName($artist['name']), $basic['artists']);
        $artist = implode(', ', $artists);

        return [
            'id' => $basic['id'],
            'instanceId' => $entry['instance_id'],
            'artist' => $artist,
            'artists' => $artists,
            'title' => $basic['title'],
            'displayTitle' => $basic['title'],
            'year' => $basic['year'] ?? 0,
            'originalYear' => $this->originalYear($basic, $release, $master),
            'addedAt' => $entry['date_added'],
            'label' => $basic['labels'][0]['name'] ?? '',
            'catalogNumber' => $basic['labels'][0]['catno'] ?? '',
            ...$this->formatMetadata($basic),
            'genres' => $basic['genres'] ?? [],
            'styles' => $basic['styles'] ?? [],
            'cover' => $this->placeholderCover,
            'coverSource' => $basic['cover_image'] ?: $this->placeholderCover,
            'discogsUrl' => "https://www.discogs.com/release/{$basic['id']}",
            ...$this->listeningLinks($artist, $basic['title']),
            'trackSource' => 'release',
            'tracks' => $this->tracks($release['tracklist'] ?? [], $artist),
            ...$this->releaseInfo($release, $artistBios),
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  list<array<string, mixed>>  $artistBios
     * @return array<string, mixed>
     */
    public function releaseInfo(array $release, array $artistBios): array
    {
        return [
            'discogsMasterId' => ($release['master_id'] ?? 0) ?: null,
            'discogsLabelId' => ($release['labels'][0]['id'] ?? 0) ?: null,
            'labels' => $release['labels'] ?? [],
            ...(isset($release['country']) ? ['country' => $release['country']] : []),
            'artistBios' => $artistBios,
            'releaseNotes' => $release['notes'] ?? '',
            'credits' => [
                'release' => $release['extraartists'] ?? [],
                'tracks' => $this->trackCredits($release['tracklist'] ?? []),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tracks
     * @return list<array<string, mixed>>
     */
    protected function trackCredits(array $tracks): array
    {
        $credits = [];

        foreach ($tracks as $track) {
            if (! empty($track['extraartists'])) {
                $credits[] = [
                    'position' => $track['position'] ?? '',
                    'title' => $track['title'],
                    'artists' => $track['extraartists'],
                ];
            }

            array_push($credits, ...$this->trackCredits($track['sub_tracks'] ?? []));
        }

        return $credits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    public function tracks(array $entries, string $fallbackArtist): array
    {
        return array_map(
            fn (array $track): array => $this->track($track, $fallbackArtist),
            $this->tracklist->tracks($entries),
        );
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    public function track(array $track, string $fallbackArtist): array
    {
        $artist = empty($track['artists'])
            ? $fallbackArtist
            : implode(', ', array_map(fn (array $artist): string => $this->artistName($artist['name']), $track['artists']));

        return [
            'position' => $track['position'] ?? '',
            'title' => $track['title'],
            'artist' => $artist,
            'duration' => $track['duration'] ?? '',
            ...$this->listeningLinks($artist, $track['title']),
        ];
    }

    /**
     * @return array{
     *     spotifyUrl: string,
     *     spotifySearch: bool,
     *     appleMusicUrl: string,
     *     appleMusicSearch: bool
     * }
     */
    public function listeningLinks(string $artist, string $title): array
    {
        $term = rawurlencode("{$artist} {$title}");

        return [
            'spotifyUrl' => "https://open.spotify.com/search/{$term}",
            'spotifySearch' => true,
            'appleMusicUrl' => "https://music.apple.com/be/search?term={$term}",
            'appleMusicSearch' => true,
        ];
    }

    public function artistName(string $name): string
    {
        return preg_replace('/ \([0-9]+\)$/', '', $name) ?? $name;
    }

    /** @param array<string, mixed> $basic
     * @return array{format: string, edition: string, mediaFormats: list<string>}
     */
    public function formatMetadata(array $basic): array
    {
        $formats = [];
        $editions = [];
        $media = [];
        foreach ($basic['formats'] ?? [] as $format) {
            $name = $format['name'] ?? '';
            $quantity = (int) ($format['qty'] ?? 1);
            $formats[] = ($quantity > 1 ? $quantity.' × ' : '').$name;
            foreach ($this->mediaNames as $medium => $names) {
                if (in_array($name, $names, true)) {
                    $media[] = $medium;
                }
            }
            $editions = array_merge($editions, $format['descriptions'] ?? [], empty($format['text']) ? [] : [$format['text']]);
        }

        return ['format' => implode(', ', $formats), 'edition' => implode(', ', array_unique($editions)), 'mediaFormats' => array_values(array_unique($media))];
    }

    /** @return list<string> */
    public function mediaFormats(string $description): array
    {
        $formats = [];
        foreach ($this->mediaNames as $format => $names) {
            if ($format === 'Vinyl') {
                $names[] = 'LP';
            }
            $alternatives = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names));
            $pattern = '/\b(?:\d+\s*[x×]\s*)?(?:'.$alternatives.')\b/i';
            if (preg_match($pattern, $description, $match, PREG_OFFSET_CAPTURE)) {
                $formats[$format] = $match[0][1];
            }
        }
        asort($formats);

        return array_keys($formats);
    }

    /**
     * @param  array<string, mixed>  $basic
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $master
     */
    protected function originalYear(array $basic, array $release, array $master): ?int
    {
        $formats = array_merge(...array_map(
            fn (array $format): array => $format['descriptions'] ?? [],
            $basic['formats'] ?? [],
        ));

        $originalYear = $master['year'] ?? null;

        if (empty($basic['master_id'])) {
            if (! array_intersect(['Reissue', 'Remastered'], $formats)) {
                $originalYear = $release['year'] ?? null;
            }
        }

        return $originalYear ?: null;
    }
}
