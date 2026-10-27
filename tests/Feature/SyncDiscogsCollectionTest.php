<?php

use App\Models\CollectionRecord;
use App\Models\User;
use App\Support\RecordCollection;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();

    $this->disk = Storage::fake('public');
    $this->collection = app(RecordCollection::class);
    $this->existing = $this->collection->snapshot()[0];
});

it('does not restore or sync a deleted legacy collection', function () {
    $this->collection->store($this->existing);
    User::freek()->delete();

    $this->artisan('records:sync-discogs')->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('collection_records', 0);
});

it('imports additions and preserves existing records on repeated runs', function () {
    $this->collection->store($this->existing);
    $addition = discogsCollectionEntry(999999991, 999999992);
    $addition['basic_information']['cover_image'] = 'https://i.discogs.com/new-record.jpg';
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([
            $addition,
            discogsCollectionEntry($this->existing['instanceId'], $this->existing['id']),
        ]),
        'https://i.discogs.com/new-record.jpg' => Http::response(file_get_contents(public_path(ltrim($this->existing['cover'], '/')))),
        'api.discogs.com/releases/999999992' => Http::response(['released' => '2026-09-24', 'tracklist' => [
            ['type_' => 'heading', 'title' => 'Side A'],
            ['type_' => 'track', 'title' => 'First Song', 'position' => 'A1', 'duration' => '3:21'],
        ]]),
        'api.discogs.com/masters/999999991' => Http::response(['year' => 1981]),
    ]);

    $this->artisan('records:sync-discogs')->assertSuccessful();
    $this->artisan('records:sync-discogs')->assertSuccessful();

    Http::assertSentCount(5);
    $this->assertDatabaseCount('collection_records', 2);
    $this->disk->assertExists("records/{$this->existing['id']}.jpg");
    $this->disk->assertExists('records/999999992.jpg');
    $this->getJson(route('recordDetails', CollectionRecord::legacy()->where('instance_id', 999999991)->firstOrFail()->public_id))
        ->assertJsonPath('cover', $this->disk->url('records/999999992.jpg'))
        ->assertJsonPath('originalYear', 1981)
        ->assertJsonPath('pressingReleaseDate', '2026-09-24')
        ->assertJsonCount(1, 'tracks')
        ->assertJsonPath('tracks.0.title', 'First Song');
    $this->getJson(route('recordDetails', CollectionRecord::legacy()->where('instance_id', $this->existing['instanceId'])->firstOrFail()->public_id))->assertJsonPath('appleMusicSearch', false);
});

it('strips Discogs disambiguation suffixes from record and track artists', function () {
    $this->collection->store($this->existing);
    $addition = discogsCollectionEntry(999999991, 999999992);
    $addition['basic_information']['artists'] = [['name' => 'Cardinals (3)']];
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([
            $addition,
            discogsCollectionEntry($this->existing['instanceId'], $this->existing['id']),
        ]),
        'api.discogs.com/releases/999999992' => Http::response(['tracklist' => [
            ['title' => 'Guest Song', 'position' => 'A1', 'artists' => [['name' => 'Cardinals (3)'], ['name' => 'Guest (12)']]],
        ]]),
        'api.discogs.com/masters/999999991' => Http::response(['year' => 1981]),
    ]);

    $this->artisan('records:sync-discogs')->assertSuccessful();

    $this->getJson(route('recordDetails', CollectionRecord::legacy()->where('instance_id', 999999991)->firstOrFail()->public_id))
        ->assertJsonPath('artist', 'Cardinals')
        ->assertJsonPath('tracks.0.artist', 'Cardinals, Guest')
        ->assertJsonPath('tracks.0.spotifyUrl', 'https://open.spotify.com/search/Cardinals%2C%20Guest%20Guest%20Song');
});

it('skips a release that cannot be fetched and still imports the others', function () {
    $this->collection->store($this->existing);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([
            discogsCollectionEntry(999999991, 999999992),
            discogsCollectionEntry(999999993, 999999994),
            discogsCollectionEntry($this->existing['instanceId'], $this->existing['id']),
        ]),
        'api.discogs.com/releases/999999992' => Http::response([], 404),
        'api.discogs.com/releases/999999994' => Http::response(['tracklist' => []]),
        'api.discogs.com/masters/999999991' => Http::response(['year' => 1981]),
    ]);

    $this->artisan('records:sync-discogs')->assertFailed();

    $this->assertDatabaseMissing('collection_records', ['instance_id' => 999999991]);
    $this->assertDatabaseHas('collection_records', ['instance_id' => 999999993]);
});

it('removes records that are no longer in the Discogs collection', function () {
    [$kept, $sold] = $this->collection->snapshot();
    $this->collection->storeMany([$kept, $sold]);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([discogsCollectionEntry($kept['instanceId'], $kept['id'])]),
    ]);

    $this->artisan('records:sync-discogs')->assertSuccessful();

    $this->assertDatabaseHas('collection_records', ['instance_id' => $kept['instanceId']]);
    $this->assertDatabaseMissing('collection_records', ['instance_id' => $sold['instanceId']]);
});

it('keeps all records when Discogs returns an incomplete collection', function () {
    [$first, $second] = $this->collection->snapshot();
    $this->collection->storeMany([$first, $second]);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([discogsCollectionEntry($first['instanceId'], $first['id'])], items: 2),
    ]);

    $this->artisan('records:sync-discogs')->assertSuccessful();

    $this->assertDatabaseCount('collection_records', 2);
});

it('imports the bundled snapshot before syncing an empty database', function () {
    $snapshot = $this->collection->snapshot();
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage(array_map(
            fn (array $record): array => discogsCollectionEntry($record['instanceId'], $record['id']),
            $snapshot,
        )),
    ]);

    $this->artisan('records:sync-discogs')->assertSuccessful();

    $this->assertDatabaseCount('collection_records', count($snapshot));
});

it('does not clear the existing collection when Discogs fails', function () {
    $this->collection->store($this->existing);
    Http::fake(['api.discogs.com/users/*' => Http::response([], 503)]);

    $this->artisan('records:sync-discogs')->assertFailed();

    Http::assertSentCount(1);
    $this->assertDatabaseCount('collection_records', 1);
});

it('purges the edge cache after a sync that changes the collection', function () {
    configureEdgeCachePurging();
    [$kept, $sold] = $this->collection->snapshot();
    $this->collection->storeMany([$kept, $sold]);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([discogsCollectionEntry($kept['instanceId'], $kept['id'])]),
        'cloud.laravel.com/*' => Http::response(),
    ]);

    $this->artisan('records:sync-discogs')
        ->expectsOutput('Purged the edge cache.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://cloud.laravel.com/api/environments/env-123/purge-edge-cache'
        && $request->hasHeader('Authorization', 'Bearer purge-token'));
});

it('keeps the edge cache when a sync changes nothing', function () {
    configureEdgeCachePurging();
    $this->existing['cover'] = $this->disk->url("records/{$this->existing['id']}.jpg");
    $this->collection->store($this->existing);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([discogsCollectionEntry($this->existing['instanceId'], $this->existing['id'])]),
        'cloud.laravel.com/*' => Http::response(),
    ]);

    $this->artisan('records:sync-discogs')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cloud.laravel.com'));
});

it('skips the edge cache purge when it is not configured', function () {
    [$kept, $sold] = $this->collection->snapshot();
    $this->collection->storeMany([$kept, $sold]);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([discogsCollectionEntry($kept['instanceId'], $kept['id'])]),
    ]);

    $this->artisan('records:sync-discogs')
        ->expectsOutput('The edge cache is not configured, so cached pages update within a day.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('finishes the sync when the edge cache purge fails', function () {
    configureEdgeCachePurging();
    [$kept, $sold] = $this->collection->snapshot();
    $this->collection->storeMany([$kept, $sold]);
    Http::fake([
        'api.discogs.com/users/*' => discogsCollectionPage([discogsCollectionEntry($kept['instanceId'], $kept['id'])]),
        'cloud.laravel.com/*' => Http::response(['message' => 'This action is unauthorized.'], 403),
    ]);

    $this->artisan('records:sync-discogs')
        ->expectsOutput('The edge cache could not be purged, so cached pages update within a day.')
        ->assertSuccessful();

    $this->assertDatabaseMissing('collection_records', ['instance_id' => $sold['instanceId']]);
});

it('can rerun the snapshot import without duplicating collection copies', function () {
    $this->artisan('records:import-snapshot')->assertSuccessful();
    $this->artisan('records:import-snapshot')->assertSuccessful();

    $this->assertDatabaseCount('collection_records', 850);
});

it('backfills release information for every copy and resumes without replacing personal data or listening links', function () {
    configureEdgeCachePurging();
    $first = socialRecord(User::freek(), activity: false, releaseId: 201);
    $second = socialRecord(socialUser('infosecond'), instance: 456, releaseId: 201);
    $second->update(['shared_note' => 'A gift', 'private_note' => 'Cost €20']);
    socialRecord($first->user, instance: 202, releaseId: 202);
    $remaining = socialRecord($first->user, instance: 789, releaseId: 999999992);
    $originalPayload = $second->payload;
    $releaseId = $first->discogs_release_id;
    $releaseCredit = ['id' => 634, 'name' => 'Brian Eno', 'role' => 'Producer', 'tracks' => ''];
    $trackCredit = ['id' => 5049, 'name' => 'Robert Wyatt', 'role' => 'Piano'];
    Http::fake([
        "api.discogs.com/releases/{$releaseId}" => Http::response([
            'master_id' => 5899,
            'labels' => [['id' => 151, 'name' => 'Polydor', 'catno' => 'AMBIENT-1']],
            'artists' => [['id' => 634, 'name' => 'Brian Eno'], ['id' => 5049, 'name' => 'Robert Wyatt']],
            'notes' => "Original pressing.\nPrinted inner sleeve.",
            'extraartists' => [$releaseCredit],
            'tracklist' => [['title' => 'Suite', 'position' => 'A1', 'sub_tracks' => [
                ['title' => 'Movement', 'position' => 'A1.1', 'extraartists' => [$trackCredit]],
            ]]],
        ]),
        'api.discogs.com/artists/634' => Http::response(['name' => 'Brian Eno', 'profile' => 'Works with [a=Robert Wyatt].']),
        'api.discogs.com/artists/5049' => Http::response(['name' => 'Robert Wyatt']),
        'api.discogs.com/releases/202' => Http::response([]),
        'api.discogs.com/releases/999999992' => Http::sequence()->push([], 503)->push([]),
        'cloud.laravel.com/*' => Http::response([], 202),
    ]);

    $this->artisan('records:import-discogs-info')->assertFailed();
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '/purge-edge-cache')))->toHaveCount(1);
    expect($first->fresh()->payload['releaseNotes'])->toBe("Original pressing.\nPrinted inner sleeve.")
        ->and($first->fresh()->payload['artistBios'])->toBe([
            ['id' => 634, 'name' => 'Brian Eno', 'profile' => 'Works with [a=Robert Wyatt].', 'discogsUrl' => 'https://www.discogs.com/artist/634'],
            ['id' => 5049, 'name' => 'Robert Wyatt', 'profile' => '', 'discogsUrl' => 'https://www.discogs.com/artist/5049'],
        ])
        ->and($first->fresh()->payload['credits'])->toEqual([
            'release' => [$releaseCredit],
            'tracks' => [['position' => 'A1.1', 'title' => 'Movement', 'artists' => [$trackCredit]]],
        ])
        ->and($first->fresh()->release->discogs_master_id)->toBe(5899)
        ->and($first->fresh()->release->discogs_label_id)->toBe(151)
        ->and($first->fresh()->payload['labels'])->toBe([['id' => 151, 'name' => 'Polydor', 'catno' => 'AMBIENT-1']])
        ->and($second->fresh()->payload)->toEqual([...$originalPayload, ...array_intersect_key($first->fresh()->payload, array_flip(['artistBios', 'releaseNotes', 'credits', 'labels', 'discogsMasterId', 'discogsLabelId', 'discogsTextLinks']))])
        ->and($second->fresh()->shared_note)->toBe('A gift')
        ->and($second->fresh()->private_note)->toBe('Cost €20')
        ->and($second->fresh()->added_at->equalTo($second->added_at))->toBeTrue()
        ->and($first->fresh()->is_activity)->toBeFalse()
        ->and($second->fresh()->is_activity)->toBeTrue()
        ->and($remaining->fresh()->payload)->not->toHaveKey('releaseNotes');

    $this->artisan('records:import-discogs-info')->assertSuccessful();
    expect($remaining->fresh()->payload['releaseNotes'])->toBe('')
        ->and($remaining->fresh()->payload['artistBios'])->toBe([])
        ->and($remaining->fresh()->payload['credits'])->toEqual(['release' => [], 'tracks' => []]);
    $this->artisan('records:import-discogs-info')->expectsOutput('Imported information for 0 releases.')->assertSuccessful();
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '/purge-edge-cache')))->toHaveCount(2);
    $this->assertDatabaseCount('collection_records', 4);
});

it('backfills names in numeric Discogs references on previously imported releases and leaves missing references usable', function () {
    $record = socialRecord(socialUser('textlinks', 'everyone'), releaseId: 201);
    $record->release->update(['info_imported_at' => now()]);
    $notes = 'A2. track 1 from [r=35332762]. Also [r35332762], [a=77], [l77] and [r404].';
    Http::fake([
        'api.discogs.com/releases/201' => Http::response([
            'notes' => $notes,
            'artists' => [['id' => 1, 'name' => 'Main artist']],
        ]),
        'api.discogs.com/artists/1' => Http::response(['name' => 'Main artist', 'profile' => 'Works with [a77]. See [r=35332762].']),
        'api.discogs.com/releases/35332762' => Http::response(['title' => 'A Real Album']),
        'api.discogs.com/artists/77' => Http::response(['name' => 'Another Artist']),
        'api.discogs.com/labels/77' => Http::response(['name' => 'A Record Label']),
        'api.discogs.com/releases/404' => Http::response([], 404),
    ]);

    $this->artisan('records:import-discogs-info')->assertSuccessful();

    expect($record->fresh()->payload['discogsTextLinks'])->toBe([
        'r35332762' => 'A Real Album', 'a77' => 'Another Artist', 'l77' => 'A Record Label', 'r404' => '',
    ])->and($record->fresh()->payload['releaseNotes'])->toBe($notes);

    $this->get($record->url(false))->assertInertia(fn (Assert $page) => $page
        ->where('recordOverlay.record.payload.discogsTextLinks.r35332762', 'A Real Album')
        ->where('recordOverlay.record.payload.discogsTextLinks.a77', 'Another Artist')
        ->where('recordOverlay.record.payload.discogsTextLinks.l77', 'A Record Label'));

    $this->artisan('records:import-discogs-info')->expectsOutput('Imported information for 0 releases.')->assertSuccessful();
});

function configureEdgeCachePurging(): void
{
    config([
        'services.laravel_cloud.purge_token' => 'purge-token',
        'services.laravel_cloud.environment_id' => 'env-123',
    ]);
}

/** @param array<int, array<string, mixed>> $entries */
function discogsCollectionPage(array $entries, ?int $items = null): PromiseInterface
{
    return Http::response([
        'pagination' => ['pages' => 1, 'items' => $items ?? count($entries)],
        'releases' => $entries,
    ]);
}
