<?php

use App\Jobs\SyncUserCollection;
use App\Support\Discogs;
use App\Support\DiscogsOAuth;
use App\Support\DiscogsRateLimitException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
    $this->freezeTime();
    config(['cache.default' => 'file', 'services.discogs.consumer_key' => 'test', 'services.discogs.consumer_secret' => 'secret']);
});

it('paces separate collectors and OAuth through the same budget without throttling cached public data', function () {
    $first = socialUser('budgetfirst');
    $second = socialUser('budgetsecond');
    foreach ([$first, $second] as $user) {
        $user->update(['discogs_token' => 'token-'.$user->id, 'discogs_secret' => 'secret']);
    }
    $sentAt = [];
    Http::fake(function () use (&$sentAt) {
        $sentAt[] = now()->getTimestampMs();

        return Http::response(['title' => 'Shared release']);
    });

    (new Discogs($first))->get('releases/101');
    $beforeCached = now()->getTimestampMs();
    expect((new Discogs($second))->get('releases/101')['title'])->toBe('Shared release')
        ->and(now()->getTimestampMs())->toBe($beforeCached);
    (new Discogs($second))->get('users/budgetsecond/collection/folders/0/releases');
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'token', 'secret');

    expect($sentAt)->toHaveCount(3)
        ->and($sentAt[1] - $sentAt[0])->toBe(1100)
        ->and($sentAt[2] - $sentAt[1])->toBe(1100);
});

it('shares the slower unauthenticated legacy budget with OAuth', function () {
    config(['services.discogs.token' => null]);
    $sentAt = [];
    Http::fake(function () use (&$sentAt) {
        $sentAt[] = now()->getTimestampMs();

        return Http::response([]);
    });
    (new Discogs)->get('releases/101');
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'token', 'secret');

    expect($sentAt[1] - $sentAt[0])->toBe(2500);
});

it('adjusts pacing to the reported limit and remaining budget', function (array $headers, int $delay) {
    Http::fakeSequence()->push([], 200, $headers)->push([]);
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'first', 'secret');
    $start = now()->getTimestampMs();
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'second', 'secret');

    expect(now()->getTimestampMs() - $start)->toBe($delay);
})->with([
    'lower total' => [['X-Discogs-Ratelimit' => '20'], 3000],
    'available budget' => [['X-Discogs-Ratelimit' => '60', 'X-Discogs-Ratelimit-Remaining' => '15'], 1100],
    'used without remaining' => [['X-Discogs-Ratelimit' => '20', 'X-Discogs-Ratelimit-Used' => '18'], 3000],
    'malformed headers' => [['X-Discogs-Ratelimit' => '0', 'X-Discogs-Ratelimit-Remaining' => 'unknown'], 1100],
]);

it('does not allow an overlapping worker to send before the first response has updated the budget', function () {
    Http::fake(function () {
        expect(fn () => (new DiscogsOAuth)->request('GET', 'oauth/identity', 'second', 'secret'))
            ->toThrow(DiscogsRateLimitException::class);

        return Http::response([], 200, ['X-Discogs-Ratelimit-Remaining' => '0']);
    });
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'first', 'secret');
    expect(fn () => (new DiscogsOAuth)->request('GET', 'oauth/identity', 'third', 'secret'))
        ->toThrow(DiscogsRateLimitException::class);
    Http::assertSentCount(1);
});

it('honours shared cooldowns before allowing another collector to send', function (int $status, array $headers, int $delay) {
    Http::fakeSequence()->push([], $status, $headers)->push(['resumed' => true]);
    if ($status === 200) {
        (new DiscogsOAuth)->request('GET', 'oauth/identity', 'first', 'secret');
    } else {
        expect(fn () => (new DiscogsOAuth)->request('GET', 'oauth/identity', 'first', 'secret'))
            ->toThrow($status === 429 ? DiscogsRateLimitException::class : RequestException::class);
    }
    $start = now();
    expect(fn () => (new DiscogsOAuth)->request('GET', 'oauth/identity', 'second', 'secret'))
        ->toThrow(DiscogsRateLimitException::class);
    Http::assertSentCount(1);
    $this->travel($delay - 1)->seconds();
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'second', 'secret');
    expect(now()->diffInMilliseconds($start, true))->toBeGreaterThanOrEqual($delay * 1000);
    Http::assertSentCount(2);
})->with([
    'exhausted success' => [200, ['X-Discogs-Ratelimit-Remaining' => '0'], 60],
    'used full budget' => [200, ['X-Discogs-Ratelimit' => '60', 'X-Discogs-Ratelimit-Used' => '60'], 60],
    'used overrides contradictory remaining' => [200, ['X-Discogs-Ratelimit' => '60', 'X-Discogs-Ratelimit-Used' => '60', 'X-Discogs-Ratelimit-Remaining' => '2'], 60],
    '429 without retry header' => [429, [], 60],
    'longer retry header' => [429, ['Retry-After' => '90'], 90],
    'short retry cannot weaken 429 cooldown' => [429, ['Retry-After' => '2'], 60],
    'service unavailable with retry header' => [503, ['Retry-After' => '75'], 75],
]);

it('honours HTTP-date Retry-After across clients', function () {
    $retryAt = now()->addSeconds(95);
    Http::fakeSequence()->push([], 429, ['Retry-After' => $retryAt->toRfc7231String()])->push([]);
    try {
        (new DiscogsOAuth)->request('GET', 'oauth/identity', 'first', 'secret');
        $this->fail('Expected a rate-limit delay');
    } catch (DiscogsRateLimitException $exception) {
        expect($exception->retryAfter)->toBe(95);
    }
    $this->travel(89)->seconds();
    expect(fn () => (new DiscogsOAuth)->request('GET', 'oauth/identity', 'second', 'secret'))
        ->toThrow(DiscogsRateLimitException::class);
    Http::assertSentCount(1);
    $this->travel(6)->seconds();
    (new DiscogsOAuth)->request('GET', 'oauth/identity', 'second', 'secret');
    Http::assertSentCount(2);
});

it('reschedules repeated rate limits without exhausting retries losing saved copies or publishing initial activity', function () {
    config(['queue.default' => 'database']);
    $user = socialUser('limitedimport');
    $user->update(['discogs_username' => $user->handle, 'discogs_token' => 'token', 'discogs_secret' => 'secret']);
    socialRecord($user, 'Preserve until complete', false, instance: 999);
    $first = discogsCollectionEntry(101, 201);
    $second = discogsCollectionEntry(102, 202);
    $first['basic_information']['master_id'] = $second['basic_information']['master_id'] = 0;
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$first, $second]]),
        'api.discogs.com/releases/201' => Http::response(['tracklist' => [['title' => 'Already saved', 'position' => 'A1']]]),
        'api.discogs.com/releases/202' => Http::sequence()->push([], 429, ['Retry-After' => '90'])
            ->push([], 429)->push([], 429)->push([], 429)
            ->push(['tracklist' => [['title' => 'Eventually imported', 'position' => 'A1']]]),
    ]);
    SyncUserCollection::dispatch($user->id);
    foreach (range(1, 4) as $attempt) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        expect($user->records()->orderBy('instance_id')->pluck('instance_id')->all())->toBe([101, 999])
            ->and($user->records()->where('is_activity', true)->count())->toBe(0)
            ->and($user->fresh()->collection_imported_at)->toBeNull()
            ->and($user->fresh()->discogs_sync_state['page'])->toBe(1)
            ->and($user->fresh()->sync_status)->toBe('Syncing');
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->sole();
        expect($job->available_at - now()->timestamp)->toBe($attempt === 1 ? 90 : 60);
        $this->travelTo(now()->setTimestamp($job->available_at));
    }
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($user->records()->pluck('instance_id')->all())->toBe([101, 102])
        ->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->fresh()->discogs_sync_state)->toBeNull()
        ->and($user->records()->where('instance_id', 102)->sole()->payload['tracks'][0]['title'])->toBe('Eventually imported')
        ->and($user->records()->where('is_activity', true)->count())->toBe(0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('gives a small update a turn before a large import finishes while preserving the global request pace', function () {
    config(['queue.default' => 'database']);
    $large = socialUser('largeimport');
    $small = socialUser('smallupdate');
    foreach ([$large, $small] as $user) {
        $user->update(['discogs_username' => $user->handle, 'discogs_token' => 'token-'.$user->id, 'discogs_secret' => 'secret']);
    }
    $small->update(['collection_imported_at' => now()->subHour(), 'last_synced_at' => now()->subHour()]);
    $largeEntries = collect(range(1, 40))->map(function ($instance) {
        $entry = discogsCollectionEntry($instance, 2000 + $instance);
        $entry['basic_information']['master_id'] = 0;

        return $entry;
    })->all();
    $recent = discogsCollectionEntry(501, 2501);
    $recent['date_added'] = now()->toIso8601String();
    $recent['basic_information']['master_id'] = 0;
    $old = discogsCollectionEntry(502, 2502);
    $old['date_added'] = now()->subDays(2)->toIso8601String();
    $sentAt = [];
    Http::fake(function ($request) use ($largeEntries, $recent, $old, &$sentAt) {
        $sentAt[] = now()->getTimestampMs();
        if (str_contains($request->url(), '/users/largeimport/')) {
            return Http::response(['pagination' => ['pages' => 1, 'items' => 40], 'releases' => $largeEntries]);
        }
        if (str_contains($request->url(), '/users/smallupdate/')) {
            return Http::response(['pagination' => ['pages' => 100, 'items' => 10000], 'releases' => [$recent, $old]]);
        }

        return Http::response(['tracklist' => [['title' => 'Imported track', 'position' => 'A1']]]);
    });
    SyncUserCollection::dispatch($large->id);
    SyncUserCollection::dispatch($small->id, full: false);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($sentAt)->toHaveCount(12)
        ->and($large->records()->count())->toBeGreaterThan(0)->toBeLessThan(40)
        ->and($large->fresh()->collection_imported_at)->toBeNull();

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($small->fresh()->sync_status)->toBe('Additions checked')
        ->and($small->records()->sole()->instance_id)->toBe(501)
        ->and($large->fresh()->collection_imported_at)->toBeNull();

    foreach (range(1, 10) as $turn) {
        $this->travel(2)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        if ($large->fresh()->collection_imported_at !== null) {
            break;
        }
    }
    expect($large->records()->count())->toBe(40)
        ->and($large->fresh()->sync_status)->toBe('Up to date')
        ->and($large->records()->where('is_activity', true)->count())->toBe(0);
    foreach (array_slice($sentAt, 1) as $index => $timestamp) {
        expect($timestamp - $sentAt[$index])->toBeGreaterThanOrEqual(1100);
    }
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('resumes a compilation with more missing artist profiles than fit in one request allowance', function () {
    config(['queue.default' => 'database']);
    $user = socialUser('compilation');
    $user->update(['discogs_username' => 'compilation', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $entry = discogsCollectionEntry(101, 201);
    $entry['basic_information']['master_id'] = 0;
    $artists = collect(range(1, 15))->map(fn ($id) => ['id' => $id, 'name' => 'Artist '.$id])->all();
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$entry]]),
        'api.discogs.com/releases/*' => Http::response(['artists' => $artists, 'tracklist' => [['title' => 'Compilation track', 'position' => 'A1']]]),
        'api.discogs.com/artists/*' => Http::response([], 404),
    ]);
    SyncUserCollection::dispatch($user->id);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($user->records()->count())->toBe(0)->and($user->fresh()->collection_imported_at)->toBeNull();
    $this->travel(2)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->records()->sole()->payload['artistBios'])->toHaveCount(15)
        ->and($user->records()->sole()->payload['tracks'][0]['title'])->toBe('Compilation track');
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('keeps one resumable import across hourly nightly and manual sync requests after more than a day', function () {
    config(['queue.default' => 'database']);
    $user = socialUser('longimport');
    $user->update(['discogs_username' => 'longimport', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    socialRecord($user, 'Keep until reconciliation finishes', false, instance: 999);
    $startedAt = now()->timestamp;
    $first = discogsCollectionEntry(101, 201);
    $second = discogsCollectionEntry(102, 202);
    $first['basic_information']['master_id'] = $second['basic_information']['master_id'] = 0;
    Http::fake([
        'api.discogs.com/users/*' => Http::sequence()
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$first]])
            ->push([], 429)
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$second]]),
        'api.discogs.com/releases/*' => Http::response(['tracklist' => [['title' => 'Imported once', 'position' => 'A1']]]),
    ]);
    SyncUserCollection::dispatch($user->id);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($user->records()->orderBy('instance_id')->pluck('instance_id')->all())->toBe([101, 999])
        ->and($user->fresh()->discogs_sync_state['page'])->toBe(2);

    foreach ([3, 23] as $hours) {
        $this->travel($hours)->hours();
        $this->artisan('social:sync-collections', ['--quick' => true])->assertSuccessful();
        $this->artisan('social:sync-collections')->assertSuccessful();
        $this->actingAs($user)->post('/discogs/sync')->assertRedirect();

        $this->assertDatabaseCount('jobs', 1);
        expect($user->fresh()->discogs_sync_state['page'])->toBe(2)
            ->and($user->fresh()->collection_imported_at)->toBeNull();
    }

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect($user->records()->pluck('instance_id')->all())->toBe([101, 102])
        ->and($user->records()->where('is_activity', true)->count())->toBe(0)
        ->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->fresh()->discogs_additions_checked_at->timestamp)->toBe($startedAt)
        ->and($user->fresh()->discogs_sync_state)->toBeNull();
    Http::assertSentCount(5);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);

    $this->artisan('social:sync-collections', ['--quick' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 1);
});
