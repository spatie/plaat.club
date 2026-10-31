<?php

use App\Jobs\BackfillDiscogsMasterId;
use App\Models\DiscogsResponse;
use App\Models\RecordList;
use App\Models\Release;
use App\Support\Discogs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
    $this->freezeTime();
    config(['queue.default' => 'database']);
});

it('queues all missing releases including list only releases without duplicate pending jobs', function () {
    $owner = socialUser('master_backfill');
    $copy = socialRecord($owner, releaseId: 201);
    $copy->release->update(['discogs_master_id' => null]);
    $listRelease = Release::query()->create(['id' => 202, 'data' => ['tracks' => []]]);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Wishlist']);
    $list->entries()->create(['release_id' => $listRelease->id, 'position' => 1, 'private_note' => 'Keep']);
    Release::query()->create(['id' => 203, 'discogs_master_id' => 999, 'data' => []]);

    $this->artisan('records:backfill-discogs-master-ids')->assertSuccessful();
    $this->artisan('records:backfill-discogs-master-ids')->assertSuccessful();
    $this->assertDatabaseCount('jobs', 2);
    Http::assertNothingSent();
    Http::fake(['api.discogs.com/releases/*' => Http::response(['master_id' => 456])]);
    $copies = DB::table('collection_records')->get()->all();
    $entries = DB::table('record_list_entries')->get()->all();
    foreach ([201, 202] as $releaseId) {
        $before = (array) DB::table('releases')->find($releaseId);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
        expect((array) DB::table('releases')->find($releaseId))->toBe([...$before, 'discogs_master_id' => 456]);
    }
    expect(DB::table('collection_records')->get()->all())->toEqual($copies)
        ->and(DB::table('record_list_entries')->get()->all())->toEqual($entries);
    $this->assertDatabaseHas('releases', ['id' => 203, 'discogs_master_id' => 999]);
    $this->assertDatabaseCount('jobs', 0);
    $this->artisan('records:backfill-discogs-master-ids')->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
});

it('refuses a synchronous backfill', function () {
    config(['queue.default' => 'sync']);
    $this->artisan('records:backfill-discogs-master-ids')->assertFailed();
    Http::assertNothingSent();
});

it('uses cached release responses without making requests', function (array $response, ?int $masterId) {
    Release::query()->create(['id' => 201, 'data' => []]);
    DiscogsResponse::query()->create([
        'resource_type' => 'releases', 'discogs_id' => 201, 'response' => $response,
        'fetched_at' => now(), 'expires_at' => now()->addDay(),
    ]);

    (new BackfillDiscogsMasterId(201))->handle(app(Discogs::class));
    (new BackfillDiscogsMasterId(201))->handle(app(Discogs::class));

    expect(Release::query()->findOrFail(201)->discogs_master_id)->toBe($masterId);
    Http::assertNothingSent();
})->with([
    'master' => [['master_id' => 456], 456],
    'zero master' => [['master_id' => 0], null],
    'absent master' => [['title' => 'Standalone release'], null],
]);

it('skips releases filled after dispatch and deleted releases', function () {
    Release::query()->create(['id' => 201, 'discogs_master_id' => 456, 'data' => []]);
    (new BackfillDiscogsMasterId(201))->handle(app(Discogs::class));
    (new BackfillDiscogsMasterId(202))->handle(app(Discogs::class));
    Http::assertNothingSent();
});

it('does not overwrite an ID concurrently filled during the request', function () {
    Release::query()->create(['id' => 201, 'data' => []]);
    Http::fake(function () {
        DB::table('releases')->where('id', 201)->update(['discogs_master_id' => 789]);

        return Http::response(['master_id' => 456]);
    });
    (new BackfillDiscogsMasterId(201))->handle(app(Discogs::class));
    $this->assertDatabaseHas('releases', ['id' => 201, 'discogs_master_id' => 789]);
});

it('reports no master and not found separately without changing the release', function (int $status, array $response, string $outcome) {
    $release = Release::query()->create(['id' => 201, 'title' => 'Keep', 'data' => ['tracks' => []]]);
    $before = $release->fresh()->getRawOriginal();
    Log::spy();
    Http::fake(['api.discogs.com/releases/201' => Http::response($response, $status)]);
    (new BackfillDiscogsMasterId(201))->handle(app(Discogs::class));
    expect($release->fresh()->getRawOriginal())->toBe($before);
    Log::shouldHaveReceived('info')->once()->with('Discogs master ID backfill', ['releaseId' => 201, 'outcome' => $outcome]);
})->with([
    'no master' => [200, ['master_id' => 0], 'no_master'],
    'not found' => [404, [], 'not_found'],
]);

it('releases repeated rate limits beyond the attempt limit and eventually fills the ID', function () {
    Release::query()->create(['id' => 201, 'data' => []]);
    Http::fake(['api.discogs.com/releases/201' => Http::sequence()
        ->push([], 429, ['Retry-After' => '90'])->push([], 429)->push([], 429)
        ->push([], 429)->push([], 429)->push([], 429)->push(['master_id' => 456])]);
    BackfillDiscogsMasterId::dispatch(201);
    foreach (range(1, 6) as $attempt) {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseHas('releases', ['id' => 201, 'discogs_master_id' => null]);
        $this->assertDatabaseCount('failed_jobs', 0);
        $job = DB::table('jobs')->sole();
        expect($job->available_at - now()->timestamp)->toBe($attempt === 1 ? 90 : 60);
        $this->travelTo(now()->setTimestamp($job->available_at));
    }
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseHas('releases', ['id' => 201, 'discogs_master_id' => 456]);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('retries transport and HTTP failures rather than reporting no master', function (bool $transport) {
    Release::query()->create(['id' => 201, 'data' => []]);
    Log::spy();
    $responses = Http::sequence()->pushResponse($transport ? Http::failedConnection() : Http::response([], 503))
        ->push(['master_id' => 456]);
    Http::fake(['api.discogs.com/releases/201' => $responses]);
    BackfillDiscogsMasterId::dispatch(201);
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseHas('releases', ['id' => 201, 'discogs_master_id' => null]);
    Log::shouldNotHaveReceived('info');
    $job = DB::table('jobs')->sole();
    $this->travelTo(now()->setTimestamp($job->available_at));
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'release-enrichment', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseHas('releases', ['id' => 201, 'discogs_master_id' => 456]);
    $this->assertDatabaseCount('jobs', 0);
})->with([true, false]);
