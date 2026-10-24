<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property array<string, mixed> $data
 * @property ?string $artist
 * @property ?string $title
 * @property ?string $cover
 * @property ?string $cover_preview
 * @property ?CarbonImmutable $info_imported_at
 */
class Release extends Model
{
    public const PAYLOAD_COLUMNS = [
        'artist' => 'artist', 'title' => 'title', 'displayTitle' => 'display_title',
        'year' => 'year', 'originalYear' => 'original_year',
        'originalReleaseDate' => 'original_release_date', 'pressingReleaseDate' => 'pressing_release_date',
        'originalDateSource' => 'original_date_source', 'musicbrainzId' => 'musicbrainz_id',
        'discogsMasterId' => 'discogs_master_id', 'discogsLabelId' => 'discogs_label_id',
        'label' => 'label', 'catalogNumber' => 'catalog_number', 'format' => 'format', 'edition' => 'edition',
        'cover' => 'cover', 'coverSource' => 'cover_source', 'coverPreview' => 'cover_preview',
        'discogsUrl' => 'discogs_url', 'appleMusicUrl' => 'apple_music_url', 'appleMusicSearch' => 'apple_music_search',
        'spotifyUrl' => 'spotify_url', 'spotifySearch' => 'spotify_search',
        'bandcampUrl' => 'bandcamp_url', 'bandcampSearch' => 'bandcamp_search',
        'trackSource' => 'track_source', 'releaseNotes' => 'release_notes',
    ];

    public const JSON_FIELDS = ['artists', 'labels', 'mediaFormats', 'genres', 'styles', 'tracks', 'credits'];

    public $incrementing = false;

    protected $guarded = [];

    protected $with = ['artists'];

    /** @return HasMany<CollectionRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(CollectionRecord::class, 'discogs_release_id');
    }

    /** @return BelongsToMany<Artist, $this> */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class)->withPivot('position')->orderByPivot('position');
    }

    public function embeddingText(): string
    {
        return implode("\n", [
            'Album: '.mb_substr($this->artist.' — '.$this->title, 0, 250),
            'Original year: '.($this->original_year ?? ''),
            'Genres: '.implode(', ', $this->data['genres'] ?? []),
            'Styles: '.implode(', ', $this->data['styles'] ?? []),
        ]);
    }

    /** @return array<string, mixed> */
    public function recordData(): array
    {
        $data = $this->metadata();
        if ($this->info_imported_at !== null || $this->artists->isNotEmpty()) {
            $data['artistBios'] = $this->artists->map(fn (Artist $artist): array => [
                'id' => $artist->id, 'name' => $artist->name, 'profile' => $artist->profile ?? '',
                'discogsUrl' => "https://www.discogs.com/artist/{$artist->id}",
            ])->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    protected function metadata(): array
    {
        $data = $this->data ?? [];
        foreach (self::PAYLOAD_COLUMNS as $field => $column) {
            $value = $this->getAttribute($column);
            if ($value !== null || in_array($field, ['originalYear', 'originalReleaseDate', 'pressingReleaseDate', 'originalDateSource', 'musicbrainzId'], true)) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    protected function casts(): array
    {
        return [
            'data' => 'array', 'info_imported_at' => 'immutable_datetime',
            'year' => 'integer', 'original_year' => 'integer',
            'discogs_master_id' => 'integer', 'discogs_label_id' => 'integer',
            'apple_music_search' => 'boolean', 'spotify_search' => 'boolean',
            'bandcamp_search' => 'boolean', 'musicbrainz_checked_at' => 'immutable_datetime',
        ];
    }
}
