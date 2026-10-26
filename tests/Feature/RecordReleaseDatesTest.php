<?php

use App\Models\RecordReleaseDate;
use App\Support\RecordCollection;
use App\Support\RecordReleaseDates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps the precision of partial dates without inventing days', function (?string $input, ?string $expected) {
    expect(app(RecordReleaseDates::class)->normalize($input))->toBe($expected);
})->with([
    ['1970', '1970'],
    ['1970-00-00', '1970'],
    ['1970-11-00', '1970-11'],
    ['1970-11-27', '1970-11-27'],
    ['1970-02-30', null],
    ['0000', null],
    [null, null],
]);

it('matches a single MusicBrainz release group by title and artist', function () {
    $record = ['displayTitle' => 'Green', 'artists' => ['Hiroshi Yoshimura'], 'originalYear' => null];

    $match = app(RecordReleaseDates::class)->match($record, [musicBrainzReleaseGroup()]);

    expect($match)->toBe(['date' => '1986', 'id' => 'green-release']);
});

it('rejects ambiguous MusicBrainz editions', function () {
    $record = ['displayTitle' => 'Green', 'artists' => ['Hiroshi Yoshimura'], 'originalYear' => null];

    $match = app(RecordReleaseDates::class)->match($record, [musicBrainzReleaseGroup(), musicBrainzReleaseGroup()]);

    expect($match)->toBeNull();
});

it('rejects MusicBrainz release groups by a different artist', function () {
    $record = ['displayTitle' => 'Green', 'artists' => ['Hiroshi Yoshimura'], 'originalYear' => null];

    $match = app(RecordReleaseDates::class)->match($record, [musicBrainzReleaseGroup(artist: 'R.E.M.')]);

    expect($match)->toBeNull();
});

it('enriches pages with stored original dates without overwriting the pressing date', function () {
    $collection = app(RecordCollection::class);
    $collection->store($collection->snapshot()[0]);
    RecordReleaseDate::query()->create([
        'discogs_release_id' => 19772260,
        'original_release_date' => '1970-11-27',
        'original_date_source' => 'musicbrainz',
        'pressing_release_date' => '2021-08-06',
    ]);

    $this->followingRedirects()->get(route('collection'))->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.originalReleaseDate', '1970-11-27')
        ->where('records.data.0.originalYear', 1970));

    $publicId = collect(app(RecordCollection::class)->records())->firstWhere('instanceId', 2189134981)['publicId'];
    $this->getJson(route('recordDetails', ['instanceId' => $publicId]))
        ->assertJsonPath('originalReleaseDate', '1970-11-27')
        ->assertJsonPath('pressingReleaseDate', '2021-08-06');
});

it('preserves a verified date on later date syncs', function () {
    $record = app(RecordCollection::class)->snapshot()[0];
    app(RecordCollection::class)->store($record);
    RecordReleaseDate::query()->create([
        'discogs_release_id' => $record['id'],
        'original_release_date' => '1970-11-27',
        'original_date_source' => 'musicbrainz',
        'musicbrainz_id' => 'verified-id',
    ]);

    $this->artisan('records:sync-dates')->assertSuccessful();

    $this->assertDatabaseHas('record_release_dates', [
        'discogs_release_id' => $record['id'],
        'original_release_date' => '1970-11-27',
        'musicbrainz_id' => 'verified-id',
    ]);
});

it('leaves dates unknown when MusicBrainz fails', function () {
    app(RecordCollection::class)->store(recordWithoutOriginalDate());
    Http::preventStrayRequests();
    Http::fake(['musicbrainz.org/ws/2/release-group/*' => Http::response([], 503)]);
    Sleep::fake();

    $this->artisan('records:sync-dates --musicbrainz')->assertSuccessful();

    Http::assertSentCount(1);
    $this->assertDatabaseHas('record_release_dates', ['discogs_release_id' => 999999999, 'original_release_date' => null]);
    $this->assertDatabaseCount('collection_records', 1);
});

it('stores MusicBrainz matches even when the response cache directory does not exist yet', function () {
    app(RecordCollection::class)->store(recordWithoutOriginalDate());
    Http::preventStrayRequests();
    Http::fake(['musicbrainz.org/ws/2/release-group/*' => Http::response(['release-groups' => [
        musicBrainzReleaseGroup(title: 'A Record', artist: 'The Artist'),
    ]])]);
    Sleep::fake();

    $this->artisan('records:sync-dates --musicbrainz')->assertSuccessful();

    $this->assertDatabaseHas('record_release_dates', [
        'discogs_release_id' => 999999999,
        'original_release_date' => '1986',
        'original_date_source' => 'musicbrainz',
    ]);
});

/** @return array<string, mixed> */
function musicBrainzReleaseGroup(string $title = 'Green', string $artist = 'Hiroshi Yoshimura'): array
{
    return [
        'id' => 'green-release',
        'title' => $title,
        'score' => 100,
        'first-release-date' => '1986',
        'artist-credit' => [['artist' => ['name' => $artist]]],
    ];
}

/** @return array<string, mixed> */
function recordWithoutOriginalDate(): array
{
    return [
        ...app(RecordCollection::class)->snapshot()[0],
        'id' => 999999999,
        'displayTitle' => 'A Record',
        'artists' => ['The Artist'],
        'originalYear' => null,
        'originalReleaseDate' => null,
        'originalDateSource' => null,
    ];
}
