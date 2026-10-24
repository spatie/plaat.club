<?php

use App\Actions\Discogs\CompleteCollectionSyncAction;
use App\Actions\Discogs\ImportDiscogsRecordAction;
use App\Jobs\SyncUserCollection;
use App\Notifications\CollectionImported;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
    config(['services.discogs.consumer_key' => 'test-consumer', 'services.discogs.consumer_secret' => 'test-secret']);
});

it('completes session-bound single-use OAuth, verifies identity, and encrypts tokens at rest', function () {
    Bus::fake();
    $user = socialUser('oauth');
    Http::fake([
        'api.discogs.com/oauth/request_token' => Http::response('oauth_token=request-token&oauth_token_secret=request-secret&oauth_callback_confirmed=true'),
        'api.discogs.com/oauth/access_token' => Http::response('oauth_token=access-token&oauth_token_secret=access-secret'),
        'api.discogs.com/oauth/identity' => Http::response(['username' => 'discogs-owner']),
    ]);
    $this->actingAs($user)->post('/discogs/connect')->assertRedirect('https://www.discogs.com/oauth/authorize?oauth_token=request-token');
    $this->get('/discogs/callback?oauth_token=request-token&oauth_verifier=verified')->assertRedirect('/friends')->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Discogs connected.');
    $fresh = $user->fresh();
    expect($fresh->discogs_username)->toBe('discogs-owner')->and($fresh->discogs_token)->toBe('access-token');
    $stored = DB::table('users')->where('id', $user->id)->first();
    expect($stored->discogs_token)->not->toBe('access-token')->and($stored->discogs_secret)->not->toBe('access-secret');
    expect($fresh->toArray())->not->toHaveKeys(['discogs_token', 'discogs_secret', 'email', 'password']);
    Bus::assertDispatched(SyncUserCollection::class, fn ($job) => $job->userId === $user->id);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.discogs.com/oauth/access_token' && $request->method() === 'POST' && str_contains($request->header('Authorization')[0], 'oauth_verifier="verified"') && str_contains($request->header('Authorization')[0], 'oauth_signature_method="HMAC-SHA1"'));
    $this->get('/discogs/callback?oauth_token=request-token&oauth_verifier=verified')->assertForbidden();
    Http::assertSentCount(3);
    $this->actingAs($user->fresh())->post('/discogs/sync')->assertRedirect()->assertSessionHas('status', 'Collection sync requested.');
    $this->delete('/discogs')->assertRedirect();
    expect($user->fresh()->discogs_token)->toBeNull()->and($user->fresh()->discogs_username)->toBe('discogs-owner');
    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['discogs_connected', 'discogs_disconnected']);
    expect(Activity::all()->toJson())->not->toContain('access-token', 'access-secret', 'request-secret');
});

it('requests an additions check from the manual sync endpoint', function () {
    Bus::fake();
    $user = socialUser('manualsync');
    $user->update(['discogs_token' => 'access']);

    $this->actingAs($user)->post('/discogs/sync')->assertRedirect()->assertSessionHas('status', 'Collection sync requested.');

    Bus::assertDispatched(SyncUserCollection::class, fn ($job) => $job->userId === $user->id && ! $job->full);
});

it('reports only the signed-in collector’s import progress through completion and failure', function () {
    $this->getJson('/discogs/status')->assertUnauthorized();
    $user = socialUser('importprogress');
    $user->update(['email_verified_at' => null]);
    $this->actingAs($user)->getJson('/discogs/status')->assertForbidden();
    $user->update(['email_verified_at' => now(), 'discogs_token' => 'private-access', 'discogs_secret' => 'private-secret', 'sync_status' => 'Syncing', 'sync_total' => 850]);
    socialRecord(socialUser('anotherimporter'), 'Another collector’s private record');
    socialRecord($user, 'My first record', activity: false)->update(['private_note' => 'Not in import summaries']);
    $response = $this->getJson('/discogs/status')->assertOk()
        ->assertExactJson([
            'collectorId' => $user->id, 'collectionUrl' => route('profile', $user->handle), 'visibility' => 'Only me',
            'connected' => true, 'complete' => false, 'count' => 1, 'total' => 850, 'status' => 'Syncing', 'failed' => false,
        ])
        ->assertDontSee('private-access')->assertDontSee('private-secret')->assertDontSee('Another collector');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->get('/friends')->assertOk()->assertDontSee('Not in import summaries')->assertDontSee('Another collector’s private record')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.syncStatus', 'Syncing')->where('auth.user.syncTotal', 850)
            ->where('collectionCount', 1)->where('auth.user.importedAt', null)
            ->has('importRecords', 1)->where('importRecords.0.displayTitle', 'My first record'));

    $user->update(['sync_status' => 'Sync failed. Existing records were kept.']);
    $this->getJson('/discogs/status')->assertJsonPath('failed', true)->assertJsonPath('count', 1);
    $this->get('/friends')->assertInertia(fn (Assert $page) => $page->where('auth.user.syncStatus', 'Sync failed. Existing records were kept.'));
    $user->update(['handle' => 'renamedcollector', 'visibility' => 'friends', 'sync_status' => 'Up to date', 'collection_imported_at' => now()]);
    $this->getJson('/discogs/status')->assertJsonPath('complete', true)->assertJsonPath('failed', false)
        ->assertJsonPath('collectorId', $user->id)->assertJsonPath('collectionUrl', url('/renamedcollector'))
        ->assertJsonPath('visibility', 'Friends');
    $this->get('/friends')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.profileUrl', '/renamedcollector')->where('auth.user.importedAt', $user->collection_imported_at->toIso8601String()));
});

it('rejects forged, expired and cross-user callbacks without reaching Discogs', function (string $scenario) {
    $user = socialUser('oauth');
    $this->actingAs($user)->withSession(['discogs_pending' => [
        'token' => 'expected', 'secret' => Crypt::encryptString('secret'),
        'user_id' => $scenario === 'other_user' ? $user->id + 1 : $user->id,
        'expires' => now()->addMinutes($scenario === 'expired' ? -1 : 15)->timestamp,
    ]])->get('/discogs/callback?oauth_token='.($scenario === 'forged' ? 'forged' : 'expected').'&oauth_verifier=verified')->assertForbidden();
    Http::assertNothingSent();
})->with(['forged', 'expired', 'other_user']);

it('does not attach a Discogs identity that already belongs to another profile', function () {
    Bus::fake();
    socialUser('existing')->update(['discogs_username' => 'already-connected']);
    $user = socialUser('newcomer');
    Http::fake([
        'api.discogs.com/oauth/access_token' => Http::response('oauth_token=access&oauth_token_secret=secret'),
        'api.discogs.com/oauth/identity' => Http::response(['username' => 'already-connected']),
    ]);
    $this->actingAs($user)->withSession(['discogs_pending' => ['token' => 'expected', 'secret' => Crypt::encryptString('secret'), 'user_id' => $user->id, 'expires' => now()->addMinutes(15)->timestamp]])
        ->get('/discogs/callback?oauth_token=expected&oauth_verifier=verified')->assertRedirect('/settings')->assertSessionHasErrors('discogs');
    expect($user->fresh()->discogs_token)->toBeNull();
    Bus::assertNothingDispatched();
});

it('imports paginated collections silently, isolates duplicate copy ids, then mirrors additions and removals', function () {
    $user = socialUser('sync');
    $user->update(['discogs_username' => 'collector', 'discogs_token' => 'test-access', 'discogs_secret' => 'test-access-secret']);
    $other = socialUser('other');
    socialRecord($other, 'Other copy', instance: 101);
    $entry = discogsCollectionEntry(101, 201);
    $entry['basic_information']['master_id'] = 0;
    $second = [...$entry, 'instance_id' => 102];
    $newEntry = [...$entry, 'instance_id' => 103, 'date_added' => '2026-10-18T09:30:00+02:00'];
    Http::fake([
        'api.discogs.com/users/*' => Http::sequence()
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$entry]])
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$second]])
            ->push(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$second, $newEntry]])
            ->push(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$second, $newEntry]])
            ->push(['pagination' => ['pages' => 1, 'items' => 0], 'releases' => []]),
        'api.discogs.com/releases/*' => Http::response(['year' => 1987, 'tracklist' => [['title' => 'Song', 'position' => 'A1']]]),
    ]);
    $job = new SyncUserCollection($user->id);
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->count())->toBe(2)->and($user->records()->where('is_activity', true)->count())->toBe(0)
        ->and($user->fresh()->collection_imported_at)->not->toBeNull()->and($user->fresh()->sync_total)->toBe(2);
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->pluck('instance_id')->all())->toBe([102, 103])
        ->and($user->records()->where('is_activity', true)->pluck('instance_id')->all())->toBe([103]);
    expect($user->records()->where('instance_id', 103)->first()->added_at->format('Y-m-d H:i:s'))->toBe('2026-10-18 07:30:00');
    expect($other->records()->first()->payload['title'])->toBe('Other copy');
    $this->actingAs($user)->get('/sync?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('records.total', 2));
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->count())->toBe(0)->and($other->records()->count())->toBe(1)->and($user->fresh()->sync_total)->toBe(0);
    $this->get('/sync?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 0)->has('groups', 0));
    $activities = Activity::query()->where('log_name', 'discogs')->orderBy('id')->get();
    expect($activities->pluck('event')->all())->toBe(['initial_import_started', 'initial_import_completed'])
        ->and($activities->last()->getProperty('records_count'))->toBe(2)
        ->and($activities->pluck('causer_id')->unique()->all())->toBe([$user->id]);
});

it('resumes a deployment-interrupted import without removing records or announcing initial additions', function () {
    $user = socialUser('deployment');
    $user->update(['discogs_username' => 'deployment', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    socialRecord($user, 'Previously saved copy', false, instance: 303);
    $first = discogsCollectionEntry(301, 401);
    $first['basic_information']['master_id'] = 0;
    $second = [...$first, 'instance_id' => 302, 'basic_information' => [...$first['basic_information'], 'id' => 402]];
    $job = new SyncUserCollection($user->id);
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$first, $second]]),
        'api.discogs.com/releases/401' => Http::response(['tracklist' => []]),
        'api.discogs.com/releases/402' => function () use ($job) {
            $job->interrupted(SIGTERM);

            return Http::response(['tracklist' => []]);
        },
    ]);
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->orderBy('instance_id')->pluck('instance_id')->all())->toBe([301, 303])
        ->and($user->fresh()->collection_imported_at)->toBeNull()
        ->and($user->fresh()->sync_status)->toBe('Syncing');
    $this->assertDatabaseCount('activity_log', 1);
    $this->assertDatabaseHas('activity_log', ['event' => 'initial_import_started', 'causer_id' => $user->id]);
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$first, $second]]),
        'api.discogs.com/releases/402' => Http::response(['tracklist' => []]),
    ]);
    (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->pluck('instance_id')->all())->toBe([301, 302])
        ->and($user->records()->where('is_activity', true)->count())->toBe(0)
        ->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->fresh()->collection_imported_at)->not->toBeNull();
    $this->assertDatabaseCount('activity_log', 2);
    $this->assertDatabaseHas('activity_log', ['event' => 'initial_import_completed', 'causer_id' => $user->id]);
});

it('preserves collections and initial-import suppression through partial responses and interrupted retries', function () {
    $user = socialUser('partial');
    $user->update(['discogs_username' => 'partial', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $entry = discogsCollectionEntry(301, 401);
    $entry['basic_information']['master_id'] = 0;
    $second = [...$entry, 'instance_id' => 302];
    socialRecord($user, 'Kept after failure', false, instance: 303);
    Http::fake([
        'api.discogs.com/users/*' => Http::sequence()
            ->push(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$entry]])
            ->push(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$entry, $second]])
            ->push([], 503)
            ->push([], 503)
            ->push([], 503),
        'api.discogs.com/releases/*' => Http::response(['tracklist' => []]),
    ]);
    $job = new SyncUserCollection($user->id);
    expect(fn () => $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class)))->toThrow(RuntimeException::class);
    expect($user->records()->count())->toBe(2)->and($user->fresh()->collection_imported_at)->toBeNull()->and($user->fresh()->sync_total)->toBe(2)
        ->and($user->fresh()->sync_status)->toBe('Retrying automatically. Existing records were kept.');
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->count())->toBe(2)->and($user->records()->where('is_activity', true)->count())->toBe(0);
    expect(fn () => $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class)))->toThrow(RuntimeException::class, 'HTTP 503');
    expect($user->records()->count())->toBe(2)->and($user->fresh()->sync_total)->toBeNull();
    $job->failed(new RuntimeException('Retries exhausted'));
    expect($user->fresh()->sync_status)->toStartWith('Sync failed');
});

it('finishes a large initial collection over multiple real queue reservations without premature pruning or activity', function () {
    config(['queue.default' => 'database']);
    $user = socialUser('bounded');
    $user->update(['discogs_username' => 'bounded', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    socialRecord($user, 'Keep until the full listing is checked', false, instance: 9999);
    $entries = collect(range(1, 126))->map(fn ($instance) => discogsCollectionEntry($instance, 2000 + $instance));
    Http::fake([
        'api.discogs.com/users/*' => function (Request $request) use ($entries) {
            return Http::response(['pagination' => ['pages' => 5, 'items' => 126], 'releases' => $entries->slice(($request['page'] - 1) * 30, 30)->values()->all()]);
        },
        'api.discogs.com/releases/*' => Http::response(['tracklist' => [['title' => 'Complete track', 'position' => 'A1']]]),
        'api.discogs.com/masters/*' => Http::response(['year' => 1974]),
    ]);
    SyncUserCollection::dispatch($user->id);
    foreach (range(1, 30) as $run) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        expect($user->records()->where('is_activity', true)->count())->toBe(0);
        if ($user->fresh()->collection_imported_at !== null) {
            break;
        }
        expect($user->records()->where('instance_id', 9999)->exists())->toBeTrue()
            ->and($user->fresh()->toArray())->not->toHaveKey('discogs_sync_state');
        $this->assertDatabaseCount('jobs', 1);
        $this->travel(2)->seconds();
    }
    expect($user->records()->count())->toBe(126)
        ->and($user->records()->where('instance_id', 9999)->exists())->toBeFalse()
        ->and($user->fresh()->collection_imported_at)->not->toBeNull()
        ->and($user->fresh()->last_synced_at)->not->toBeNull()
        ->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->records()->where('instance_id', 126)->firstOrFail()->payload['originalYear'])->toBe(1974)
        ->and($user->records()->where('instance_id', 126)->firstOrFail()->payload['tracks'][0]['title'])->toBe('Complete track');
    $this->assertDatabaseCount('jobs', 1);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('emails only completed initial imports above 50 records, including resumed imports', function (int $total) {
    Notification::fake();
    $user = socialUser('celebrate');
    $user->update(['discogs_username' => 'celebrate', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $entries = collect(range(1, $total))->map(function (int $instance) use ($user) {
        socialRecord($user, instance: $instance, activity: false);

        return discogsCollectionEntry($instance, 2000 + $instance);
    });
    socialRecord($user, 'Removed before counting', instance: 9999, activity: false);
    Http::fake([
        'api.discogs.com/users/*' => fn (Request $request) => Http::response([
            'pagination' => ['pages' => (int) ceil($total / 10), 'items' => $total],
            'releases' => $entries->slice(($request['page'] - 1) * 10, 10)->values()->all(),
        ]),
    ]);
    $sync = fn () => (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    $sync();

    if ($total > 50) {
        expect($user->fresh()->collection_imported_at)->toBeNull();
        Notification::assertNothingSent();
        $sync();
    }

    expect($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->records()->count())->toBe($total);
    $sync();
    if ($total <= 50) {
        Notification::assertNothingSent();

        return;
    }

    $sync();
    expect($user->fresh()->sync_status)->toBe('Up to date');
    Notification::assertSentToTimes($user, CollectionImported::class, 1);
    Notification::assertSentTo($user, CollectionImported::class, fn (CollectionImported $mail) => $mail->recordCount === $total);
})->with([50, 51]);

it('does not email an incomplete large import', function () {
    Notification::fake();
    $user = socialUser('incomplete');
    $user->update(['discogs_username' => 'incomplete', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 51], 'releases' => []]),
    ]);

    expect(fn () => (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class)))
        ->toThrow(RuntimeException::class);
    expect($user->fresh()->collection_imported_at)->toBeNull();
    Notification::assertNothingSent();
});

it('renders a personal import celebration with the collection link in HTML and plain text', function () {
    $user = socialUser('celebration');
    $user->update(['name' => '<em>Alex & Robin</em>']);
    $notification = new CollectionImported(1234);
    $message = $notification->toMail($user);
    $html = (string) $message->render();
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());

    expect($html)->toContain('1,234 records', '&lt;em&gt;Alex &amp; Robin&lt;/em&gt;', 'href="'.route('profile', $user->handle).'"')
        ->not->toContain('<em>Alex & Robin</em>');
    expect($text)->toContain('1,234 records', 'View your collection: '.route('profile', $user->handle))
        ->not->toContain('<table', '<style');
    expect($notification->shouldSend($user, 'mail'))->toBeTrue();
    $user->update(['email_verified_at' => null]);
    expect($notification->shouldSend($user, 'mail'))->toBeFalse();
});

it('checks additions across timestamp ties without scanning old pages or applying deletions', function () {
    $this->travelTo(now()->startOfSecond());
    $user = socialUser('quick');
    $checkedAt = now()->subHour()->toImmutable();
    $user->update([
        'discogs_username' => 'quick', 'discogs_token' => 'access', 'discogs_secret' => 'secret',
        'collection_imported_at' => now()->subDay(), 'last_synced_at' => $checkedAt,
        'discogs_additions_checked_at' => $checkedAt,
    ]);
    socialRecord($user, 'Removed on Discogs, keep until reconciliation', instance: 999);
    socialRecord($user, 'Known recent copy', activity: false, instance: 101);
    $entries = collect([101, 102, 103, 104, 105])->map(function ($instance) use ($checkedAt) {
        $entry = discogsCollectionEntry($instance, $instance + 100);
        $entry['basic_information']['master_id'] = 0;
        $entry['date_added'] = match ($instance) {
            101 => $checkedAt->addMinute()->toIso8601String(),
            102 => $checkedAt->toIso8601String(),
            103, 104 => $checkedAt->subMinute()->toIso8601String(),
            105 => $checkedAt->subSeconds(61)->toIso8601String(),
        };

        return $entry;
    });
    Http::fake([
        'api.discogs.com/users/*' => function (Request $request) use ($entries) {
            expect($request['sort'])->toBe('added')->and($request['sort_order'])->toBe('desc');
            expect($request['page'])->toBeLessThanOrEqual(2);

            return Http::response(['pagination' => ['pages' => 100, 'items' => 10000], 'releases' => $request['page'] === 1 ? $entries->take(3)->all() : $entries->slice(3)->values()->all()]);
        },
        'api.discogs.com/releases/*' => Http::response(['tracklist' => []]),
    ]);

    (new SyncUserCollection($user->id, full: false))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));

    expect($user->records()->orderBy('instance_id')->pluck('instance_id')->all())->toBe([101, 102, 103, 104, 999])
        ->and($user->records()->where('is_activity', true)->where('instance_id', '!=', 999)->orderBy('instance_id')->pluck('instance_id')->all())->toBe([102, 103, 104])
        ->and($user->fresh()->last_synced_at->equalTo($checkedAt))->toBeTrue()
        ->and($user->fresh()->discogs_additions_checked_at->equalTo($checkedAt->addHour()))->toBeTrue()
        ->and($user->fresh()->sync_status)->toBe('Additions checked')
        ->and($user->fresh()->discogs_sync_state)->toBeNull();
    Http::assertSentCount(5);
});

it('does not advance the additions checkpoint on an incomplete response', function () {
    $user = socialUser('quickfailure');
    $checkedAt = now()->subHour()->startOfSecond()->toImmutable();
    $user->update([
        'discogs_username' => 'quickfailure', 'discogs_token' => 'access', 'discogs_secret' => 'secret',
        'collection_imported_at' => $checkedAt, 'last_synced_at' => $checkedAt, 'discogs_additions_checked_at' => $checkedAt,
    ]);
    socialRecord($user, instance: 999);
    Http::fake(['api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 2, 'items' => 200], 'releases' => []])]);

    expect(fn () => (new SyncUserCollection($user->id, full: false))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class)))
        ->toThrow(RuntimeException::class);
    expect($user->fresh()->discogs_additions_checked_at->equalTo($checkedAt))->toBeTrue()
        ->and($user->records()->pluck('instance_id')->all())->toBe([999]);
});

it('uses a full scan for first imports and overdue reconciliation even when a quick sync was requested', function (bool $initial) {
    $user = socialUser('quickfallback');
    $user->update([
        'discogs_username' => 'quickfallback', 'discogs_token' => 'access', 'discogs_secret' => 'secret',
        'collection_imported_at' => $initial ? null : now()->subDays(3),
        'last_synced_at' => $initial ? null : now()->subDays(2),
    ]);
    socialRecord($user, instance: 999);
    $entry = discogsCollectionEntry(101, 201);
    $entry['basic_information']['master_id'] = 0;
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$entry]]),
        'api.discogs.com/releases/*' => Http::response(['tracklist' => []]),
    ]);

    (new SyncUserCollection($user->id, full: false))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));

    expect($user->records()->pluck('instance_id')->all())->toBe([101])
        ->and($user->records()->sole()->is_activity)->toBe(! $initial)
        ->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->fresh()->last_synced_at)->not->toBeNull();
})->with([true, false]);

it('resumes the unfinished page after exhausted connection retries and reuses already fetched public details', function () {
    $user = socialUser('checkpoint');
    $user->update(['discogs_username' => 'checkpoint', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $first = discogsCollectionEntry(301, 401);
    $first['basic_information']['master_id'] = 0;
    $second = discogsCollectionEntry(302, 402);
    Http::fake([
        'api.discogs.com/users/*' => Http::sequence()
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$first]])
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$second]])
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$second]]),
        'api.discogs.com/releases/*' => Http::sequence()
            ->push(['tracklist' => [['title' => 'Cached complete track', 'position' => 'A1']]])
            ->push(['tracklist' => [['title' => 'Cached complete track', 'position' => 'A1']]]),
        'api.discogs.com/masters/*' => Http::sequence()
            ->pushResponse(Http::failedConnection('cURL error 28: OAuth private-secret must not be logged'))
            ->pushResponse(Http::failedConnection('cURL error 28: OAuth private-secret must not be logged'))
            ->pushResponse(Http::failedConnection('cURL error 28: OAuth private-secret must not be logged'))
            ->push(['year' => 1968]),
    ]);
    expect(fn () => (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class)))
        ->toThrow(RuntimeException::class, 'ConnectionException (cURL error 28)');
    expect($user->records()->pluck('instance_id')->all())->toBe([301])
        ->and($user->fresh()->collection_imported_at)->toBeNull();
    (new SyncUserCollection($user->id))->failed(new RuntimeException('OAuth private-secret'));
    $this->assertDatabaseHas('activity_log', ['event' => 'initial_import_failed', 'causer_id' => $user->id]);
    $this->assertDatabaseMissing('activity_log', ['event' => 'initial_import_completed']);
    expect(Activity::all()->toJson())->not->toContain('private-secret');
    (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    $record = $user->records()->where('instance_id', 302)->firstOrFail();
    expect($user->records()->count())->toBe(2)->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($record->payload['originalYear'])->toBe(1968)
        ->and($record->payload['tracks'][0]['title'])->toBe('Cached complete track')
        ->and($user->records()->where('is_activity', true)->count())->toBe(0);
});

it('recovers transient Discogs request errors without restarting the collection', function (string $failure) {
    $user = socialUser('transient');
    $user->update(['discogs_username' => 'transient', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $entry = discogsCollectionEntry(101, 201);
    $entry['basic_information']['master_id'] = 0;
    $release = ['year' => 1982, 'tracklist' => [['position' => 'A1', 'title' => 'Recovered']]];
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$entry]]),
        'api.discogs.com/releases/*' => Http::sequence()
            ->pushResponse($failure === 'connection' ? Http::failedConnection() : Http::response([], (int) $failure))
            ->push($release),
    ]);
    (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->records()->sole()->payload['tracks'][0]['title'])->toBe('Recovered');
})->with(['connection', '503']);

it('shares only public release details between collectors while fetching each private collection separately', function () {
    $first = socialUser('cachefirst');
    $second = socialUser('cachesecond');
    foreach ([$first, $second] as $user) {
        $user->update(['discogs_username' => $user->handle, 'discogs_token' => 'access-'.$user->id, 'discogs_secret' => 'secret']);
    }
    Http::fake([
        'api.discogs.com/users/cachefirst/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [discogsCollectionEntry(101, 201)]]),
        'api.discogs.com/users/cachesecond/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [discogsCollectionEntry(202, 201)]]),
        'api.discogs.com/releases/*' => Http::sequence()->push([
            'artists' => [['id' => 634, 'name' => 'Brian Eno']],
            'notes' => 'Printed inner sleeve.',
            'extraartists' => [['id' => 634, 'name' => 'Brian Eno', 'role' => 'Producer', 'tracks' => '']],
            'tracklist' => [['title' => 'Shared public song', 'position' => 'A1', 'extraartists' => [
                ['id' => 5049, 'name' => 'Robert Wyatt', 'role' => 'Piano'],
            ]]],
        ]),
        'api.discogs.com/masters/*' => Http::sequence()->push(['year' => 1971]),
        'api.discogs.com/artists/634' => Http::sequence()->push(['name' => 'Brian Eno', 'profile' => 'Electronic musician and producer.']),
    ]);
    foreach ([$first, $second] as $user) {
        (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
        expect($user->fresh()->sync_status)->toBe('Up to date')
            ->and($user->records()->sole()->payload['originalYear'])->toBe(1971)
            ->and($user->records()->sole()->payload['tracks'][0]['title'])->toBe('Shared public song')
            ->and($user->records()->sole()->payload['releaseNotes'])->toBe('Printed inner sleeve.')
            ->and($user->records()->sole()->payload['artistBios'])->toBe([
                ['id' => 634, 'name' => 'Brian Eno', 'profile' => 'Electronic musician and producer.', 'discogsUrl' => 'https://www.discogs.com/artist/634'],
            ])
            ->and($user->records()->sole()->payload['credits']['release'][0]['role'])->toBe('Producer')
            ->and($user->records()->sole()->payload['credits']['tracks'])->toEqual([
                ['position' => 'A1', 'title' => 'Shared public song', 'artists' => [['id' => 5049, 'name' => 'Robert Wyatt', 'role' => 'Piano']]],
            ]);
    }
    expect($first->records()->pluck('instance_id')->all())->toBe([101])
        ->and($second->records()->pluck('instance_id')->all())->toBe([202]);
});

it('imports records without missing artist profiles but does not hide other artist request failures', function (int $status) {
    $user = socialUser('missingartist');
    $user->update(['discogs_username' => 'missingartist', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $entry = discogsCollectionEntry(101, 2828974);
    $entry['basic_information']['master_id'] = 0;
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$entry]]),
        'api.discogs.com/releases/*' => Http::response([
            'artists' => [['id' => 194, 'name' => 'Various'], ['id' => 634, 'name' => 'Brian Eno']],
            'tracklist' => [['position' => 'A1', 'title' => 'Still imported']],
        ]),
        'api.discogs.com/artists/194' => Http::response(['message' => 'Artist not found.'], $status),
        'api.discogs.com/artists/634' => Http::response(['name' => 'Brian Eno', 'profile' => 'Electronic musician and producer.']),
    ]);
    $import = fn () => (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));

    if ($status !== 404) {
        expect($import)->toThrow(RuntimeException::class, 'HTTP '.$status);
        expect($user->records()->count())->toBe(0)
            ->and($user->fresh()->collection_imported_at)->toBeNull();

        return;
    }

    $import();

    expect($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->fresh()->collection_imported_at)->not->toBeNull()
        ->and($user->records()->sole()->payload['tracks'][0]['title'])->toBe('Still imported')
        ->and($user->records()->sole()->payload['artistBios'])->toBe([
            ['id' => 194, 'name' => 'Various', 'profile' => '', 'discogsUrl' => 'https://www.discogs.com/artist/194'],
            ['id' => 634, 'name' => 'Brian Eno', 'profile' => 'Electronic musician and producer.', 'discogsUrl' => 'https://www.discogs.com/artist/634'],
        ]);
})->with([404, 403, 503]);

it('restarts a changed paginated snapshot without applying incomplete removals', function () {
    $user = socialUser('changing');
    $user->update(['discogs_username' => 'changing', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    socialRecord($user, 'Keep until a complete fresh snapshot', false, instance: 999);
    $first = discogsCollectionEntry(101, 201);
    $second = discogsCollectionEntry(102, 202);
    Http::fake([
        'api.discogs.com/users/*' => Http::sequence()
            ->push(['pagination' => ['pages' => 2, 'items' => 2], 'releases' => [$first]])
            ->push(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$second]])
            ->push(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$second]]),
        'api.discogs.com/releases/*' => Http::response(['tracklist' => []]),
        'api.discogs.com/masters/*' => Http::response(['year' => 1973]),
    ]);
    expect(fn () => (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class)))->toThrow(RuntimeException::class);
    expect($user->records()->orderBy('instance_id')->pluck('instance_id')->all())->toBe([101, 999])
        ->and($user->fresh()->collection_imported_at)->toBeNull();
    (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect($user->records()->pluck('instance_id')->all())->toBe([102])
        ->and($user->fresh()->sync_status)->toBe('Up to date')
        ->and($user->records()->where('is_activity', true)->count())->toBe(0);
});

it('fails safely without OAuth configuration or with malformed provider responses', function () {
    Bus::fake();
    $user = socialUser('missingconfig');
    config(['services.discogs.consumer_key' => null]);
    $this->actingAs($user)->from('/settings')->post('/discogs/connect')->assertRedirect('/settings')->assertSessionHasErrors('discogs');
    Http::assertNothingSent();
    config(['services.discogs.consumer_key' => 'test-consumer']);
    Http::fake(['api.discogs.com/oauth/request_token' => Http::response('oauth_token[]=unexpected&oauth_token_secret=secret&oauth_callback_confirmed=true')]);
    $this->post('/discogs/connect')->assertRedirect('/settings')->assertSessionHasErrors('discogs');
    $this->get('/discogs/callback?oauth_token=unexpected&oauth_verifier=test')->assertForbidden();
    expect($user->fresh()->discogs_token)->toBeNull();
    Bus::assertNothingDispatched();
});

it('refuses to mix a different Discogs account into an existing shelf', function () {
    Bus::fake();
    $user = socialUser('reconnect');
    $user->update(['discogs_username' => 'original-account']);
    socialRecord($user, 'Keep my original record');
    Http::fake([
        'api.discogs.com/oauth/access_token' => Http::response('oauth_token=new-access&oauth_token_secret=new-secret'),
        'api.discogs.com/oauth/identity' => Http::response(['username' => 'different-account']),
    ]);
    $this->actingAs($user)->withSession(['discogs_pending' => ['token' => 'expected', 'secret' => Crypt::encryptString('secret'), 'user_id' => $user->id, 'expires' => now()->addMinutes(15)->timestamp]])
        ->get('/discogs/callback?oauth_token=expected&oauth_verifier=verified')->assertRedirect('/settings')->assertSessionHasErrors('discogs');
    expect($user->fresh()->discogs_username)->toBe('original-account')->and($user->fresh()->discogs_token)->toBeNull();
    expect($user->records()->first()->payload['displayTitle'])->toBe('Keep my original record');
    Bus::assertNothingDispatched();
});
