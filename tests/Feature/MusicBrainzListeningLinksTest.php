<?php

use App\Actions\Collections\StoreReleaseAction;
use App\Actions\Discogs\ImportDiscogsRecordAction;
use App\Actions\MusicBrainz\EnrichReleaseListeningLinksAction;
use App\Jobs\EnrichRelease;
use App\Models\Release;
use App\Support\Discogs;
use App\Support\MusicBrainzRateLimitException;
use App\Support\RecordCollection;
use App\Support\RecordReleaseDates;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
});

function listeningLinkRelease(): Release
{
    return app(StoreReleaseAction::class)->execute(201, [
        ...app(RecordCollection::class)->snapshot()[0],
        'artist' => 'The Night Owls', 'artists' => ['The Night Owls'],
        'title' => 'Quiet Corners', 'displayTitle' => 'Quiet Corners',
        'originalYear' => 2001, 'edition' => 'LP, Album', 'musicbrainzId' => 'album-group',
        'spotifyUrl' => 'https://open.spotify.com/search/Quiet', 'spotifySearch' => true,
        'appleMusicUrl' => 'https://music.apple.com/be/search?term=Quiet', 'appleMusicSearch' => true,
        'tracks' => [['title' => 'Dawn'], ['title' => 'Late at Night']],
    ]);
}

/** @return array<string, mixed> */
function listeningLinkEdition(): array
{
    return [
        'id' => 'digital-edition', 'title' => 'Quiet Corners', 'score' => 100,
        'primary-type' => 'Album', 'first-release-date' => '2001-03-04',
        'artist-credit' => [['artist' => ['name' => 'The Night Owls']]],
        'media' => [['format' => 'Digital Media', 'track-count' => 2]],
        'relations' => array_map(fn ($url) => ['url' => ['resource' => $url]], [
            'https://open.spotify.com/album/resolved',
            'https://music.apple.com/us/album/resolved/123',
            'https://music.apple.com/be/album/resolved/123',
            'https://nightowls.bandcamp.com/album/quiet-corners',
        ]),
    ];
}

/** @return array<string, mixed> */
function listeningLinkTracks(bool $reversed = false): array
{
    $titles = $reversed ? ['Late at Night', 'Dawn'] : ['Dawn', 'Late at Night'];

    return ['media' => [['tracks' => array_map(fn ($title) => ['title' => $title], $titles)]]];
}

it('shares resolved links while preserving existing direct links and personal copy data', function () {
    $release = listeningLinkRelease();
    $release->update(['spotify_url' => 'https://open.spotify.com/album/curated', 'spotify_search' => false]);
    $owner = socialUser('listener', 'everyone');
    $copy = $owner->records()->create(['instance_id' => 301, 'discogs_release_id' => 201, 'added_at' => '2026-10-01 10:00:00', 'private_note' => 'Personal price']);
    $other = socialUser('second_listener')->records()->create(['instance_id' => 302, 'discogs_release_id' => 201, 'added_at' => '2026-10-02 10:00:00']);
    Http::fake([
        'musicbrainz.org/ws/2/release-group/album-group*' => Http::response([...listeningLinkEdition(), 'id' => 'album-group']),
        'musicbrainz.org/ws/2/release?*' => Http::response(['release-count' => 1, 'releases' => [listeningLinkEdition()]]),
        'musicbrainz.org/ws/2/release/digital-edition*' => Http::response(listeningLinkTracks()),
    ]);

    app(EnrichReleaseListeningLinksAction::class)->execute(201);

    foreach ([$copy->fresh(), $other->fresh()] as $record) {
        expect($record->payload['spotifyUrl'])->toBe('https://open.spotify.com/album/curated')
            ->and($record->payload['appleMusicUrl'])->toBe('https://music.apple.com/be/album/resolved/123')
            ->and($record->payload['bandcampUrl'])->toBe('https://nightowls.bandcamp.com/album/quiet-corners')
            ->and($record->payload['bandcampSearch'])->toBeFalse();
    }
    expect($release->fresh()->musicbrainz_checked_at)->not->toBeNull()
        ->and($copy->fresh()->private_note)->toBe('Personal price')
        ->and($copy->fresh()->added_at->toDateString())->toBe('2026-10-01')
        ->and($copy->fresh()->updated_at->equalTo($copy->updated_at))->toBeTrue();
    $this->get(route('shelf.record', $copy))->assertOk()->assertInertia(fn ($page) => $page
        ->where('record.payload.bandcampUrl', 'https://nightowls.bandcamp.com/album/quiet-corners')
        ->missing('record.privateNote'));
    $this->get(route('shelf.record', $other))->assertRedirect(route('login'));

    $sent = Http::recorded()->count();
    app(EnrichReleaseListeningLinksAction::class)->execute(201);
    Http::assertSentCount($sent);

    app(EnrichReleaseListeningLinksAction::class)->execute(201, refresh: true);
    expect($release->fresh()->spotify_url)->toBe('https://open.spotify.com/album/curated');
});

it('rejects different editions, track sequences, artists and non-album or unsafe URLs', function (string $difference) {
    listeningLinkRelease();
    $edition = listeningLinkEdition();
    if ($difference === 'deluxe') {
        $edition['disambiguation'] = 'deluxe';
    }
    if ($difference === 'bonus') {
        $edition['media'][0]['track-count'] = 3;
    }
    if ($difference === 'artist') {
        $edition['artist-credit'][0]['artist']['name'] = 'An Impostor';
    }
    if ($difference === 'unsafe') {
        $edition['relations'] = array_map(fn ($url) => ['url' => ['resource' => $url]], [
            'javascript:alert(1)', 'https://open.spotify.com.evil.test/album/nope',
            'https://nightowls.bandcamp.com/track/not-an-album',
        ]);
    }
    Http::fake([
        'musicbrainz.org/ws/2/release-group/album-group*' => Http::response([...listeningLinkEdition(), 'id' => 'album-group']),
        'musicbrainz.org/ws/2/release?*' => Http::response(['release-count' => 1, 'releases' => [$edition]]),
        'musicbrainz.org/ws/2/release/digital-edition*' => Http::response(listeningLinkTracks(reversed: $difference === 'sequence')),
    ]);

    app(EnrichReleaseListeningLinksAction::class)->execute(201);

    $data = Release::query()->findOrFail(201)->recordData();
    expect($data['spotifySearch'])->toBeTrue()
        ->and($data['appleMusicSearch'])->toBeTrue()
        ->and($data)->not->toHaveKey('bandcampUrl');
})->with(['deluxe', 'bonus', 'artist', 'sequence', 'unsafe']);

it('finds an album through its Discogs master when the pressing has no cross-reference', function () {
    $release = listeningLinkRelease();
    $release->update(['musicbrainz_id' => null, 'discogs_master_id' => 500]);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/url?')) {
            return $request['resource'] === 'https://www.discogs.com/master/500'
                ? Http::response(['relations' => [['release-group' => ['id' => 'album-group']]]])
                : Http::response([], 404);
        }
        if (str_contains($request->url(), '/release-group/')) {
            return Http::response([...listeningLinkEdition(), 'id' => 'album-group']);
        }
        if (str_contains($request->url(), '/release?')) {
            return Http::response(['release-count' => 1, 'releases' => [listeningLinkEdition()]]);
        }

        return Http::response(listeningLinkTracks());
    });

    app(EnrichReleaseListeningLinksAction::class)->execute(201);

    expect($release->fresh()->musicbrainz_id)->toBe('album-group')
        ->and($release->fresh()->bandcamp_search)->toBeFalse();
});

it('leaves ambiguous searches alone and checkpoints successful empty lookups', function () {
    $release = listeningLinkRelease();
    $release->update(['musicbrainz_id' => null]);
    Http::fake([
        'musicbrainz.org/ws/2/url?*' => Http::response([], 404),
        'musicbrainz.org/ws/2/release-group?*' => Http::response(['release-groups' => [listeningLinkEdition(), [...listeningLinkEdition(), 'id' => 'other-group']]]),
    ]);

    app(EnrichReleaseListeningLinksAction::class)->execute(201);

    expect($release->fresh()->musicbrainz_id)->toBeNull()
        ->and($release->fresh()->musicbrainz_checked_at)->not->toBeNull()
        ->and($release->fresh()->spotify_search)->toBeTrue();
});

it('checks later pages and verifies the sequence rather than trusting equal track counts', function () {
    $release = listeningLinkRelease();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/release-group/')) {
            return Http::response([...listeningLinkEdition(), 'id' => 'album-group']);
        }
        if (str_contains($request->url(), '/release?')) {
            $edition = $request['offset'] === 0 ? [...listeningLinkEdition(), 'relations' => []] : listeningLinkEdition();

            return Http::response(['release-count' => 2, 'releases' => [$edition]]);
        }

        return Http::response(listeningLinkTracks());
    });

    app(EnrichReleaseListeningLinksAction::class)->execute(201);

    expect($release->fresh()->spotify_url)->toBe('https://open.spotify.com/album/resolved');
});

it('keeps failures retryable and coordinates request spacing and server cooldown across lookups', function () {
    $release = listeningLinkRelease();
    $times = [];
    Http::fake(function () use (&$times) {
        $times[] = now()->getTimestampMs();

        return count($times) === 1
            ? Http::response([], 503, ['Retry-After' => '180'])
            : Http::response(['release-groups' => []]);
    });

    expect(fn () => app(EnrichReleaseListeningLinksAction::class)->execute(201))->toThrow(MusicBrainzRateLimitException::class)
        ->and($release->fresh()->musicbrainz_checked_at)->toBeNull();
    expect(fn () => app(EnrichReleaseListeningLinksAction::class)->execute(201))->toThrow(MusicBrainzRateLimitException::class);
    $this->travel(180)->seconds();
    $release->update(['musicbrainz_id' => null]);
    app(EnrichReleaseListeningLinksAction::class)->execute(201);

    expect($times[1] - $times[0])->toBeGreaterThanOrEqual(180000);
    foreach (array_slice($times, 2) as $index => $time) {
        expect($time - $times[$index + 1])->toBeGreaterThanOrEqual(1100);
    }
    expect($release->fresh()->musicbrainz_checked_at)->not->toBeNull();
});

it('queues releases missing either source or explicitly refreshes all releases', function (bool $refresh) {
    Bus::fake();
    $release = listeningLinkRelease();
    app(StoreReleaseAction::class)->execute(202, ['title' => 'Already checked'])->update(['info_imported_at' => now(), 'musicbrainz_checked_at' => now()]);
    app(StoreReleaseAction::class)->execute(203, ['title' => 'Missing Discogs information'])->update(['musicbrainz_checked_at' => now()]);

    $this->artisan('records:enrich-releases', ['--refresh' => $refresh])->assertSuccessful();

    Bus::assertDispatchedTimes(EnrichRelease::class, $refresh ? 3 : 2);
    Bus::assertDispatched(EnrichRelease::class, fn ($job) => $job->releaseId === $release->id && $job->refresh === $refresh && $job->queue === 'release-enrichment');
    Bus::assertDispatched(EnrichRelease::class, fn ($job) => $job->releaseId === 203);
})->with([false, true]);

it('queues every incomplete release once across batch boundaries', function () {
    Bus::fake();
    DB::table('releases')->insert(array_map(fn ($id) => [
        'id' => $id, 'data' => '[]',
        'info_imported_at' => $id % 2 ? null : now(),
        'musicbrainz_checked_at' => $id % 2 ? now() : null,
    ], range(1, 1002)));

    $this->artisan('records:enrich-releases')->assertSuccessful();

    expect(Bus::dispatched(EnrichRelease::class)->pluck('releaseId')->all())->toBe(range(1, 1002));
    Http::assertNothingSent();
});

it('enriches newly imported releases in the background and reuses the result for another collector', function () {
    Bus::fake([EnrichRelease::class]);
    $owner = socialUser('import_listener', 'everyone');
    $entry = discogsCollectionEntry(301, 201);
    $entry['basic_information'] = [
        ...$entry['basic_information'],
        'master_id' => 0, 'title' => 'Quiet Corners', 'year' => 2001,
        'artists' => [['id' => 901, 'name' => 'The Night Owls']],
    ];
    Http::fake([
        'api.discogs.com/releases/201' => Http::response([
            'year' => 2001, 'artists' => [['id' => 901, 'name' => 'The Night Owls']],
            'notes' => 'Includes a printed inner sleeve.',
            'extraartists' => [['name' => 'Another Musician', 'role' => 'Producer']],
            'tracklist' => [['title' => 'Dawn'], ['title' => 'Late at Night']],
        ]),
        'api.discogs.com/artists/901' => Http::response(['name' => 'The Night Owls', 'profile' => 'A nocturnal ensemble.']),
        'musicbrainz.org/ws/2/url?*' => Http::response(['relations' => [['release-group' => ['id' => 'album-group']]]]),
        'musicbrainz.org/ws/2/release-group/album-group*' => Http::response([...listeningLinkEdition(), 'id' => 'album-group']),
        'musicbrainz.org/ws/2/release?*' => Http::response(['release-count' => 1, 'releases' => [listeningLinkEdition()]]),
        'musicbrainz.org/ws/2/release/digital-edition*' => Http::response(listeningLinkTracks()),
    ]);

    $copy = $owner->records()->make();
    app(ImportDiscogsRecordAction::class)->execute($copy, $entry, app(Discogs::class), true, fn () => false);

    expect($copy->fresh()->payload['releaseNotes'])->toBe('Includes a printed inner sleeve.')
        ->and($copy->fresh()->payload['artistBios'][0]['profile'])->toBe('A nocturnal ensemble.')
        ->and($copy->fresh()->payload['credits']['release'][0]['role'])->toBe('Producer')
        ->and($copy->fresh()->release->info_imported_at)->not->toBeNull()
        ->and($copy->fresh()->release->musicbrainz_checked_at)->toBeNull();
    Bus::assertDispatched(EnrichRelease::class, fn ($job) => $job->releaseId === 201 && $job->afterCommit && $job->queue === 'release-enrichment');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'musicbrainz.org'));

    app()->call([new EnrichRelease(201), 'handle']);

    $this->get(route('shelf.record', $copy))->assertOk()->assertInertia(fn ($page) => $page
        ->where('record.payload.bandcampUrl', 'https://nightowls.bandcamp.com/album/quiet-corners')
        ->where('record.payload.artistBios.0.profile', 'A nocturnal ensemble.'));
    $sent = Http::recorded()->count();
    $other = socialUser('import_second')->records()->make();
    app(ImportDiscogsRecordAction::class)->execute($other, [...$entry, 'instance_id' => 302], app(Discogs::class), true, fn () => false);

    expect($other->fresh()->payload['bandcampUrl'])->toBe('https://nightowls.bandcamp.com/album/quiet-corners')
        ->and($other->fresh()->payload['releaseNotes'])->toBe('Includes a printed inner sleeve.');
    Bus::assertDispatchedTimes(EnrichRelease::class, 1);
    Http::assertSentCount($sent);
});

it('keeps imported Discogs information when MusicBrainz fails and resumes only the unfinished lookup', function () {
    $release = listeningLinkRelease();
    Http::fake([
        'api.discogs.com/releases/201' => Http::sequence()->push([
            'artists' => [['id' => 901, 'name' => 'The Night Owls']], 'notes' => 'Backfilled sleeve notes.',
            'extraartists' => [['name' => 'Another Musician', 'role' => 'Producer']],
        ]),
        'api.discogs.com/artists/901' => Http::sequence()->push(['name' => 'The Night Owls', 'profile' => 'A nocturnal ensemble.']),
        'musicbrainz.org/ws/2/release-group/album-group*' => Http::sequence()
            ->push([], 503, ['Retry-After' => '180'])
            ->push([...listeningLinkEdition(), 'id' => 'album-group']),
        'musicbrainz.org/ws/2/release?*' => Http::response(['release-count' => 0, 'releases' => []]),
    ]);

    app()->call([new EnrichRelease(201), 'handle']);

    expect($release->fresh()->info_imported_at)->not->toBeNull()
        ->and($release->fresh()->musicbrainz_checked_at)->toBeNull()
        ->and($release->fresh()->release_notes)->toBe('Backfilled sleeve notes.');

    $this->travel(180)->seconds();
    app()->call([new EnrichRelease(201), 'handle']);

    expect($release->fresh()->musicbrainz_checked_at)->not->toBeNull()
        ->and($release->fresh()->recordData()['artistBios'][0]['profile'])->toBe('A nocturnal ensemble.')
        ->and($release->fresh()->recordData()['credits']['release'][0]['role'])->toBe('Producer')
        ->and($release->fresh()->spotify_search)->toBeTrue();
    $sent = Http::recorded()->count();
    app()->call([new EnrichRelease(201), 'handle']);
    Http::assertSentCount($sent);
});

it('retains the resolved release-group ID when adding date metadata', function () {
    $record = ['id' => 201, ...listeningLinkRelease()->recordData()];

    expect(app(RecordReleaseDates::class)->enrichOne($record)['musicbrainzId'])->toBe('album-group');
});

it('finishes after repeated cooldowns without spending the error budget or extending the deadline', function () {
    $this->freezeTime();
    config(['queue.default' => 'database']);
    Bus::swap(Bus::getFacadeRoot()->dispatcher);
    $release = listeningLinkRelease();
    $release->update(['info_imported_at' => now()]);
    Http::fakeSequence()->push([], 503, ['Retry-After' => '90'])->push([], 429)->push([], 503)
        ->push([], 429)->push([], 503)->push([], 429)
        ->push([...listeningLinkEdition(), 'id' => 'album-group'])
        ->push(['release-count' => 0, 'releases' => []]);
    EnrichRelease::dispatch(201);
    $deadline = now()->addWeek()->timestamp;

    foreach (range(1, 7) as $attempt) {
        if ($attempt === 2) {
            Cache::put('musicbrainz-next-request', now()->addSeconds(125)->getTimestampMs(), 300);
        }
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('failed_jobs', 0);
        $job = DB::table('jobs')->sole();
        expect(json_decode($job->payload, true)['retryUntil'])->toBe($deadline)
            ->and($job->available_at - now()->timestamp)->toBe($attempt === 1 ? 90 : ($attempt === 2 ? 125 : 60))
            ->and($release->fresh()->musicbrainz_checked_at)->toBeNull();
        $this->travelTo(now()->setTimestamp($job->available_at));
    }

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    expect($release->fresh()->musicbrainz_checked_at)->not->toBeNull();
    Http::assertSentCount(8);
});

it('stops enrichment at the error budget or the fixed deadline', function (bool $expire) {
    config(['queue.default' => 'database']);
    Bus::swap(Bus::getFacadeRoot()->dispatcher);
    $release = listeningLinkRelease();
    $release->update(['info_imported_at' => now()]);
    Http::fakeSequence()->push([], 500)->push([], 500)->push([], 500)->push([], 500)->push([], 500);
    EnrichRelease::dispatch(201);
    if ($expire) {
        $this->travel(8)->days();
    }

    foreach (range(1, $expire ? 1 : 5) as $attempt) {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
        if (! $expire && $attempt < 5) {
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->travelTo(now()->setTimestamp(DB::table('jobs')->sole()->available_at));
        }
    }

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    expect($release->fresh()->musicbrainz_checked_at)->toBeNull();
    Http::assertSentCount($expire ? 0 : 5);
})->with([false, true]);
