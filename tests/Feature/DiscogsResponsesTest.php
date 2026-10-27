<?php

use App\Models\DiscogsResponse;
use App\Support\Discogs;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
    $this->freezeSecond();
    config([
        'cache.default' => 'array',
        'services.discogs.consumer_key' => 'test',
        'services.discogs.consumer_secret' => 'secret',
        'services.discogs.token' => null,
    ]);
});

it('shares stored public responses across collectors and anonymous requests independently of runtime cache', function (string $resourceType) {
    $first = socialUser('responsefirst');
    $second = socialUser('responsesecond');
    foreach ([$first, $second] as $user) {
        $user->update(['discogs_token' => 'token-'.$user->id, 'discogs_secret' => 'secret']);
    }
    $expected = match ($resourceType) {
        'releases' => [
            'id' => 123, 'title' => 'Public response', 'year' => 1981, 'released' => '1981-05-10', 'master_id' => 456,
            'artists' => [['id' => 7, 'name' => 'Björk']], 'labels' => [['id' => 8, 'catno' => 'ABC']],
            'notes' => "Original notes\0é", 'extraartists' => [['id' => 9, 'role' => 'Producer']],
            'tracklist' => [['title' => 'Suite', 'sub_tracks' => [
                ['title' => 'Part one', 'position' => 'A1', 'extraartists' => [['id' => 10, 'role' => 'Piano']]],
            ]]],
        ],
        'masters' => ['id' => 123, 'year' => 1981],
        'artists' => ['id' => 123, 'name' => 'Björk', 'profile' => "Biography\0é"],
        'labels' => ['id' => 123, 'name' => 'Text Records'],
    };
    $payload = [...$expected, 'images' => [['uri' => 'https://example.test/cover.jpg']], 'videos' => [['title' => 'Unused video']]];
    Http::fake(['api.discogs.com/*' => Http::response($payload)]);

    expect((new Discogs($first))->get("{$resourceType}/123"))->toBe($expected);
    Cache::flush();
    expect((new Discogs($second))->get("{$resourceType}/123"))->toEqual($expected)
        ->and((new Discogs)->get("{$resourceType}/123"))->toEqual($expected);

    $stored = DiscogsResponse::query()->sole();
    expect($stored->resource_type)->toBe($resourceType)
        ->and($stored->discogs_id)->toBe(123)
        ->and($stored->response)->toEqual($expected)
        ->and($stored->fetched_at->equalTo(now()))->toBeTrue()
        ->and($stored->expires_at->equalTo(now()->addWeek()))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->header('Authorization')[0], 'OAuth '));
})->with(['releases', 'masters', 'artists', 'labels']);

it('keeps resource types with the same Discogs ID separate', function () {
    $responses = ['releases' => ['title' => 'Release'], 'masters' => ['year' => 1981], 'artists' => ['name' => 'Artist']];
    Http::fake(fn (Request $request) => Http::response($responses[basename(dirname($request->url()))]));

    foreach (['releases', 'masters', 'artists'] as $resourceType) {
        expect((new Discogs)->get("{$resourceType}/123"))->toBe($responses[$resourceType]);
    }
    foreach (['releases', 'masters', 'artists'] as $resourceType) {
        expect((new Discogs)->get("{$resourceType}/123"))->toBe($responses[$resourceType]);
    }

    $this->assertDatabaseCount('discogs_responses', 3);
    Http::assertSentCount(3);
});

it('reuses a response for one week and replaces it at expiry', function () {
    Http::fakeSequence()->push(['title' => 'Original'])->push(['title' => 'Updated']);
    expect((new Discogs)->get('releases/123'))->toBe(['title' => 'Original']);
    $stored = DiscogsResponse::query()->sole();

    $this->travelTo($stored->expires_at->subSecond());
    expect((new Discogs)->get('releases/123'))->toBe(['title' => 'Original']);
    Http::assertSentCount(1);

    $this->travelTo($stored->expires_at);
    expect((new Discogs)->get('releases/123'))->toBe(['title' => 'Updated']);

    $refreshed = $stored->fresh();
    expect($refreshed->response)->toBe(['title' => 'Updated'])
        ->and($refreshed->fetched_at->equalTo(now()))->toBeTrue()
        ->and($refreshed->expires_at->equalTo(now()->addWeek()))->toBeTrue();
    $this->assertDatabaseCount('discogs_responses', 1);
    Http::assertSentCount(2);
});

it('prunes responses expired before or exactly now while keeping fresh responses reusable', function () {
    foreach ([now()->subSecond(), now(), now()->addSecond()] as $index => $expiresAt) {
        DiscogsResponse::query()->create([
            'resource_type' => 'releases', 'discogs_id' => $index + 1,
            'response' => ['title' => 'Stored'], 'fetched_at' => $expiresAt->copy()->subWeek(), 'expires_at' => $expiresAt,
        ]);
    }

    $this->artisan('model:prune', ['--model' => [DiscogsResponse::class], '--chunk' => 1])->assertSuccessful();

    $this->assertDatabaseCount('discogs_responses', 1);
    expect(DiscogsResponse::query()->sole()->discogs_id)->toBe(3)
        ->and((new Discogs)->get('releases/3'))->toBe(['title' => 'Stored']);
    Http::assertNothingSent();

    Http::fake(['api.discogs.com/releases/1' => Http::response(['title' => 'Refetched'])]);
    expect((new Discogs)->get('releases/1'))->toBe(['title' => 'Refetched']);
    $this->assertDatabaseCount('discogs_responses', 2);
    Http::assertSentCount(1);
});

it('bypasses public response storage for personal and query requests', function (string $path, array $query) {
    $user = socialUser('personalresponses');
    $user->update(['discogs_token' => 'personal-token', 'discogs_secret' => 'secret']);
    DiscogsResponse::query()->create([
        'resource_type' => 'releases', 'discogs_id' => 123,
        'response' => ['title' => 'Shared'], 'fetched_at' => now(), 'expires_at' => now()->addWeek(),
    ]);
    Http::fakeSequence()->push(['personal' => 'First'])->push(['personal' => 'Second']);

    expect((new Discogs($user))->get($path, $query))->toBe(['personal' => 'First'])
        ->and((new Discogs($user))->get($path, $query))->toBe(['personal' => 'Second'])
        ->and(DiscogsResponse::query()->sole()->response)->toBe(['title' => 'Shared']);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_starts_with($request->header('Authorization')[0], 'OAuth '));
})->with([
    'collection page' => ['users/personalresponses/collection/folders/0/releases', ['page' => 1]],
    'personal endpoint without query' => ['users/personalresponses/collection/folders/0/releases', []],
    'public endpoint with query' => ['releases/123', ['curr_abbr' => 'EUR']],
]);

it('stores missing artists as empty responses until their one week expiry', function () {
    Http::fakeSequence()->push(['message' => 'Not found'], 404)->push(['name' => 'Restored artist']);

    expect((new Discogs)->get('artists/123'))->toBe([])
        ->and((new Discogs)->get('artists/123'))->toBe([])
        ->and(DiscogsResponse::query()->sole()->response)->toBe([]);
    Http::assertSentCount(1);

    $this->travelTo(DiscogsResponse::query()->sole()->expires_at);
    expect((new Discogs)->get('artists/123'))->toBe(['name' => 'Restored artist']);
    Http::assertSentCount(2);
});

it('does not store errors from other public endpoints or non-404 artist errors', function (string $path, int $status) {
    Http::fake(['api.discogs.com/*' => Http::response(['message' => 'Failure'], $status)]);

    expect(fn () => (new Discogs)->get($path))->toThrow(RequestException::class);
    expect(fn () => (new Discogs)->get($path))->toThrow(RequestException::class);
    $this->assertDatabaseCount('discogs_responses', 0);
    Http::assertSentCount(2);
})->with([
    'release not found' => ['releases/123', 404],
    'master not found' => ['masters/123', 404],
    'artist unavailable' => ['artists/123', 503],
]);

it('preserves fresh legacy public responses across batches and old prefixes without copying expired or unsafe values', function () {
    if (! Schema::hasTable('cache')) {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration');
        });
    }
    $oldStore = new DatabaseStore(DB::connection(), 'cache', 'freeks-records-cache-');
    $currentStore = new DatabaseStore(DB::connection(), 'cache', 'plaat-club-cache-');
    $responses = [];
    foreach (range(1, 1001) as $id) {
        $responses["discogs-public-v1:releases/{$id}"] = ['id' => $id, 'title' => "Saved {$id}"];
    }
    foreach (array_chunk($responses, 200, preserve_keys: true) as $batch) {
        $oldStore->putMany($batch, 3600);
    }
    $currentStore->put('discogs-public-v1:releases/1', ['id' => 1, 'title' => 'Freshest'], 7200);
    $oldStore->put('discogs-public-v1:releases/2', ['id' => 2, 'title' => 'Saved 2'], 7200);
    $currentStore->put('discogs-public-v1:releases/2', ['id' => 2, 'title' => 'Stale later prefix'], 3600);
    $oldStore->put('discogs-public-v1:masters/1', ['id' => 1, 'title' => 'Master'], 3600);
    $oldStore->put('discogs-public-v1:artists/1', [], 3600);
    $oldStore->put('discogs-public-v1:artists/2', ['profile' => "Björk\0profile"], 3600);
    $oldStore->put('discogs-public-v1:releases/2001', ['title' => 'Expired'], -1);
    $oldStore->put('discogs-public-v1:releases/2002', ['title' => 'Expires now'], 0);
    $oldStore->put('discogs-public-v1:users/collector/collection', ['private' => true], 3600);
    $oldStore->put('discogs-public-v1:releases/2003?query=1', ['title' => 'Query'], 3600);
    $oldStore->put('discogs-public-v1:artists/2004', ['unsafe' => new stdClass], 3600);
    $oldStore->put('unrelated', ['value' => true], 3600);
    DB::table('cache')->insert([
        ['key' => 'discogs-public-v1:masters/2', 'value' => serialize(['id' => 2]), 'expiration' => now()->addHour()->timestamp],
        ['key' => 'discogs-public-v1:artists/2005', 'value' => 'not serialized', 'expiration' => now()->addHour()->timestamp],
    ]);
    $cacheCount = DB::table('cache')->count();
    Schema::drop('discogs_responses');

    $migration = require database_path('migrations/2026_10_23_160000_create_discogs_responses.php');
    $migration->up();

    $this->assertDatabaseCount('discogs_responses', 1005);
    $this->assertDatabaseCount('cache', $cacheCount);
    foreach (range(2, 1001) as $id) {
        expect((new Discogs)->get("releases/{$id}"))->toBe(['id' => $id, 'title' => "Saved {$id}"]);
    }
    expect((new Discogs)->get('releases/1'))->toBe(['id' => 1, 'title' => 'Freshest'])
        ->and((new Discogs)->get('masters/1'))->toBe(['id' => 1])
        ->and((new Discogs)->get('masters/2'))->toBe(['id' => 2])
        ->and((new Discogs)->get('artists/1'))->toBe([])
        ->and((new Discogs)->get('artists/2'))->toBe(['profile' => "Björk\0profile"]);
    $stored = DiscogsResponse::query()->where('resource_type', 'releases')->where('discogs_id', 1)->sole();
    expect($stored->expires_at->equalTo(now()->addHours(2)))->toBeTrue()
        ->and($stored->fetched_at->equalTo(now()->addHours(2)->subWeek()))->toBeTrue();
    Http::assertNothingSent();

    Http::fake(['api.discogs.com/releases/2001' => Http::response(['title' => 'Refetched'])]);
    expect((new Discogs)->get('releases/2001'))->toBe(['title' => 'Refetched']);
    Http::assertSentCount(1);
});

it('creates public response storage when there is no legacy cache table', function () {
    Schema::drop('discogs_responses');
    Schema::dropIfExists('cache');

    $migration = require database_path('migrations/2026_10_23_160000_create_discogs_responses.php');
    $migration->up();

    $this->assertDatabaseCount('discogs_responses', 0);
    expect(Schema::hasTable('cache'))->toBeFalse();
});

it('compacts existing public responses across batches without losing import fields or changing freshness', function () {
    $record = socialRecord(socialUser('compactresponses'));
    $record->update(['private_note' => 'Keep personal data']);
    $catalogue = $record->fresh()->payload;
    $expected = [];
    foreach (range(1, 205) as $id) {
        $resourceType = ['releases', 'masters', 'artists'][$id % 3];
        $response = match ($resourceType) {
            'releases' => ['id' => $id, 'year' => 0, 'released' => '1981-05-10', 'master_id' => null, 'notes' => "Notes é\0", 'tracklist' => [
                ['title' => 'Suite', 'sub_tracks' => [['title' => 'Part', 'extraartists' => [['id' => 87, 'role' => 'Piano']]]]],
            ], 'extraartists' => [], 'artists' => [['id' => 4, 'name' => 'Artist']], 'labels' => [['id' => 5, 'catno' => 'A']]],
            'masters' => ['id' => $id, 'year' => 1978],
            'artists' => $id === 2 ? [] : ['id' => $id, 'name' => 'Björk', 'profile' => "Bio\0é"],
        };
        $stored = DiscogsResponse::query()->create([
            'resource_type' => $resourceType, 'discogs_id' => $id,
            'response' => [...$response, 'images' => [['uri' => str_repeat('unused', 100)]], 'videos' => []],
            'fetched_at' => now()->subDays(2), 'expires_at' => now()->addDays(5),
        ]);
        $expected[$stored->id] = ['resource_type' => $resourceType, 'discogs_id' => $id, 'response' => $response];
    }
    $beforeBytes = DiscogsResponse::all()->sum(fn ($stored) => strlen(json_encode($stored->response)));
    $migration = require database_path('migrations/2026_10_24_000000_compact_discogs_responses.php');
    $migration->up();

    $this->assertDatabaseCount('discogs_responses', 205);
    foreach (DiscogsResponse::all() as $stored) {
        $original = $expected[$stored->id];
        expect($stored->resource_type)->toBe($original['resource_type'])
            ->and($stored->discogs_id)->toBe($original['discogs_id'])
            ->and($stored->response)->toEqual($original['response'])
            ->and($stored->fetched_at->equalTo(now()->subDays(2)))->toBeTrue()
            ->and($stored->expires_at->equalTo(now()->addDays(5)))->toBeTrue()
            ->and((new Discogs)->get("{$stored->resource_type}/{$stored->discogs_id}"))->toEqual($original['response']);
    }
    expect(DiscogsResponse::all()->sum(fn ($stored) => strlen(json_encode($stored->response))))->toBeLessThan($beforeBytes)
        ->and($record->fresh()->payload)->toBe($catalogue)
        ->and($record->fresh()->private_note)->toBe('Keep personal data');
    Http::assertNothingSent();
});

it('refuses to remove active database cache and preserves catalogue data when removal is safe', function () {
    $legacy = require database_path('migrations/0001_01_01_000001_create_cache_table.php');
    $legacy->up();
    $record = socialRecord(socialUser('cache_removal'));
    $record->update(['private_note' => 'Personal copy note']);
    $payload = $record->payload;
    DiscogsResponse::query()->create([
        'resource_type' => 'releases', 'discogs_id' => 42, 'response' => ['title' => 'Preserved response'],
        'fetched_at' => now(), 'expires_at' => now()->addWeek(),
    ]);
    $migration = require database_path('migrations/2026_10_23_180000_remove_database_cache.php');
    config(['cache.default' => 'database']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(Schema::hasTable('cache'))->toBeTrue()
        ->and(Schema::hasTable('cache_locks'))->toBeTrue();

    config(['cache.default' => 'array']);
    $migration->up();

    expect(Schema::hasTable('cache'))->toBeFalse()
        ->and(Schema::hasTable('cache_locks'))->toBeFalse()
        ->and($record->fresh()->payload)->toBe($payload)
        ->and($record->fresh()->private_note)->toBe('Personal copy note')
        ->and((new Discogs)->get('releases/42'))->toBe(['title' => 'Preserved response']);
    Http::assertNothingSent();
});
