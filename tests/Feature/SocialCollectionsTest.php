<?php

use App\Actions\Collections\StoreCollectionRecordAction;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Friendship;
use App\Models\RecordReleaseDate;
use App\Models\SocialNotification;
use App\Models\User;
use App\Support\RecordCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

it('shows every friend with at most five latest releases and safe attribution', function () {
    $viewer = socialUser('feedviewer');
    $first = socialUser('firstfriend', 'friends');
    $second = socialUser('secondfriend', 'everyone');
    socialFriends($viewer, $first);
    socialFriends($viewer, $second);
    foreach (range(1, 30) as $instance) {
        socialRecord($first, 'Earlier record '.$instance, date: '2026-10-01 12:00:00', instance: $instance);
    }
    $older = socialRecord($first, 'Older shared copy', date: '2026-10-15 12:00:00', instance: 500);
    $newer = socialRecord($second, 'Newer shared copy', date: '2026-10-16 12:00:00', instance: 500);
    $second->update(['discogs_token' => 'owner-token-secret', 'google_avatar_url' => 'https://example.test/avatar.jpg']);
    app(StoreCollectionRecordAction::class)->execute($newer, [
        'private_note' => 'Private feed note', 'shared_note' => 'Detail-only feed note',
        'payload' => [...$newer->payload, 'oauth_token' => 'Payload token secret', 'private_note' => 'Payload note secret',
            'title' => '</script><script>alert("feed")</script>'],
    ]);
    $hidden = socialUser('hiddenfriend', 'only_me');
    socialFriends($viewer, $hidden);
    socialRecord($hidden, 'Hidden feed record');
    $empty = socialUser('emptyfriend', 'friends');
    socialFriends($viewer, $empty);
    socialRecord($first, 'Initial feed import', activity: false, date: '2026-09-01 12:00:00', instance: 501);

    $response = $this->actingAs($viewer)->get('/friends')->assertOk()
        ->assertSeeInOrder(['Newer shared copy', 'Older shared copy'])
        ->assertDontSee('Hidden feed record')->assertDontSee('Initial feed import')
        ->assertDontSee('record-grid')->assertDontSee('record-card')
        ->assertDontSee('Private feed note')->assertDontSee('Detail-only feed note')
        ->assertDontSee('Payload token secret')->assertDontSee('Payload note secret')
        ->assertDontSee('owner-token-secret')->assertDontSee($second->email)
        ->assertDontSee('</script><script>alert', false);
    $friends = $response->inertiaProps('friends');
    $summaries = array_merge(...array_column($friends, 'records'));
    expect(array_column(array_column($friends, 'person'), 'handle'))->toBe(['secondfriend', 'firstfriend', 'emptyfriend', 'hiddenfriend'])
        ->and(array_map(fn (array $friend) => count($friend['records']), $friends))->toBe([1, 5, 0, 0])
        ->and($friends[2]['visible'])->toBeTrue()
        ->and($friends[3]['visible'])->toBeFalse()
        ->and($summaries)->toHaveCount(6)
        ->and(array_column(array_slice($summaries, 0, 2), 'instanceId'))->toBe([500, 500])
        ->and(array_column(array_slice($summaries, 0, 2), 'shareUrl'))->toBe([$newer->url(false), $older->url(false)])
        ->and($friends[0]['person']['avatarUrl'])->toBe('https://example.test/avatar.jpg')
        ->and($friends[1]['person']['profileUrl'])->toBe('/firstfriend')
        ->and($summaries[0]['addedAt'])->toBe($newer->added_at->toIso8601String())
        ->and($summaries[0])->not->toHaveKey('tracks');
    $this->get($summaries[0]['shareUrl'])->assertOk()->assertSee('Newer shared copy');
    $this->get($summaries[1]['shareUrl'])->assertOk()->assertSee('Older shared copy');
    socialRecord($first, 'Most recent import', activity: false, date: '2026-10-20 12:00:00', instance: 502);
    $this->get('/friends')->assertInertia(fn (Assert $page) => $page
        ->where('friends.0.person.handle', 'firstfriend')->has('friends.0.records', 5)
        ->where('friends.0.records.0.displayTitle', 'Most recent import')
        ->where('friends.1.person.handle', 'secondfriend'));
    $this->get('/feed')->assertStatus(301)->assertRedirect('/friends');
    $this->get('/feed?notifications=1')->assertStatus(301)->assertRedirect('/friends?notifications=1');
});

it('loads visible shelf pages with owner-specific social links and only safe summary fields', function () {
    $owner = socialUser('shelfowner', 'everyone');
    $other = socialUser('othershelf', 'everyone');
    $overlap = socialRecord($other, 'Other owner album', instance: 1);
    foreach (range(1, 30) as $instance) {
        $record = socialRecord($owner, 'Shelf album '.$instance, instance: $instance);
    }
    app(StoreCollectionRecordAction::class)->execute($record, ['private_note' => 'Secret personal note', 'shared_note' => 'Shared detail only', 'payload' => [
        ...$record->payload,
        'title' => '</script><script>alert("shelf")</script>', 'displayTitle' => 'Trimmed album',
        'artist' => 'Trimmed artist', 'artists' => ['Trimmed artist'],
        'private_note' => 'Injected payload secret', 'oauth_token' => 'Injected credential',
    ]]);
    $response = $this->get(route('profile', $owner->handle))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 30)->where('person.name', 'Shelfowner'))
        ->assertDontSee('name="format"', false)
        ->assertDontSee('Other owner album')->assertDontSee('Secret personal note')
        ->assertDontSee('Shared detail only')->assertDontSee('Injected payload secret')
        ->assertDontSee('Injected credential')->assertDontSee($owner->email)
        ->assertDontSee('</script><script>alert', false);
    $summaries = $response->inertiaProps('records.data');
    expect($summaries)->toHaveCount(30);
    $copy = $owner->records()->where('instance_id', 1)->firstOrFail();
    expect(collect($summaries)->firstWhere('instanceId', 1)['shareUrl'])->toBe($copy->url(false))
        ->not->toBe($overlap->url(false))
        ->and($summaries[0]['addedAt'])->toBe($record->added_at->toIso8601String())
        ->and($summaries[0])->not->toHaveKey('tracks')
        ->and($summaries[0]['trackTitles'])->toContain($record->payload['tracks'][0]['title']);
    $this->get(collect($summaries)->firstWhere('instanceId', 1)['shareUrl'])->assertOk()->assertSee('Shelf album 1');
    $owner->update(['visibility' => 'only_me']);
    $this->get(route('profile', $owner->handle))->assertRedirect('/login')->assertDontSee('Trimmed album')->assertDontSee('Shelf album');
});

it('pages a large collection and jumps across sections without filtering other artists out', function () {
    $owner = socialUser('large_shelf', 'everyone');
    foreach (range(1, 80) as $instance) {
        $copy = socialRecord($owner, "Album {$instance}", instance: $instance);
        app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'artist' => $instance <= 40 ? 'Alpha' : 'Zulu', 'artists' => [$instance <= 40 ? 'Alpha' : 'Zulu']]]);
    }
    $first = $this->get('/large_shelf?mode=artist')->assertOk();
    expect($first->inertiaProps('records.data'))->toHaveCount(36)
        ->and($first->inertiaProps('records.total'))->toBe(80)
        ->and($first->inertiaProps('groups'))->toBe([
            ['name' => 'Alpha', 'count' => 40, 'offset' => 0],
            ['name' => 'Zulu', 'count' => 40, 'offset' => 40],
        ]);
    $jump = $this->get('/large_shelf?mode=artist&group=Zulu')->assertOk();
    expect($jump->inertiaProps('records.current_page'))->toBe(2)
        ->and(array_unique(array_column($jump->inertiaProps('records.data'), 'section')))->toBe([0 => 'Alpha', 4 => 'Zulu']);
    $this->get('/large_shelf?mode=artist&group=Zulu', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'social/ProfilePage', 'X-Inertia-Partial-Data' => 'records,browse',
        'X-Inertia-Reset' => 'records', 'Purpose' => 'prefetch',
    ])->assertOk()->assertJsonPath('props.records.current_page', 2)
        ->assertJsonPath('props.browse.group', 'Zulu')->assertJsonMissingPath('props.groups')
        ->assertJsonMissingPath('props.auth')->assertJsonMissingPath('mergeProps')
        ->assertJsonPath('scrollProps.records.reset', true);
    $last = $this->get('/large_shelf?mode=artist&group=&recordsPage=3')->assertOk();
    $all = [...$first->inertiaProps('records.data'), ...$jump->inertiaProps('records.data'), ...$last->inertiaProps('records.data')];
    expect(array_unique(array_column($all, 'key')))->toHaveCount(80)
        ->and($last->inertiaProps('records.next_page_url'))->toBeNull();
    $owner->update(['visibility' => 'only_me']);
    $this->get('/large_shelf?mode=artist&recordsPage=2', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'social/ProfilePage', 'X-Inertia-Partial-Data' => 'records,groups',
    ])
        ->assertRedirect('/login')->assertDontSee('Album');
});

it('keeps browsing current after individual and bulk collection changes', function () {
    $owner = User::freek();
    $owner->update(['handle' => 'cached_shelf']);
    $copy = socialRecord($owner, 'First album', instance: 1);
    $this->get('/cached_shelf?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1));

    RecordReleaseDate::query()->create(['discogs_release_id' => $copy->discogs_release_id, 'original_release_date' => '1970-11-27']);
    $this->get('/cached_shelf?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('records.data.0.originalReleaseDate', '1970-11-27'));

    app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'artists' => ['Renamed artist'], 'tracks' => [['title' => 'Searchable song']]]]);
    $this->get('/cached_shelf?mode=artist&q=searchable')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', 'Renamed artist')->has('records.data', 1));

    $added = socialRecord($owner, 'Second album', instance: 2);
    $this->get('/cached_shelf?mode=decade')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('records.total', 2));
    $added->delete();
    $this->get('/cached_shelf?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('records.total', 1));

    $collection = app(RecordCollection::class);
    $collection->storeMany([[...$copy->payload, 'artists' => ['Bulk edited artist']]]);
    $this->get('/cached_shelf?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', 'Bulk edited artist'));
    if (DB::getDriverName() === 'sqlite') {
        DB::unprepared("CREATE TRIGGER reject_browse_update BEFORE INSERT ON collection_browse_entries
            WHEN NEW.name = 'Rejected artist'
            BEGIN SELECT RAISE(FAIL, 'Simulated index write failure'); END");
    } else {
        DB::statement("ALTER TABLE collection_browse_entries ADD CONSTRAINT reject_browse_update CHECK (name <> 'Rejected artist')");
    }
    $copy->refresh();
    expect(fn () => app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'title' => 'Rejected title', 'artists' => ['Rejected artist']]]))
        ->toThrow(QueryException::class);
    $this->get('/cached_shelf?mode=artist&q=searchable')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', 'Bulk edited artist')->where('records.data.0.displayTitle', 'First album'));
    $collection->removeAllExcept([]);
    $this->get('/cached_shelf?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 0)->has('groups', 0));
});

it('keeps collection browsing available without a database metadata cache', function () {
    config(['cache.default' => 'database']);
    $owner = socialUser('cache_failure', 'everyone');
    foreach ([1974, 1981] as $instance => $year) {
        $copy = socialRecord($owner, "Album {$year}", instance: $instance + 1);
        app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'originalYear' => $year, 'artists' => [$instance === 0 ? 'Cro Magnon' : 'Cromagnon']]]);
    }
    $this->get('/cache_failure?mode=decade')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 2)
            ->where('groups.0.name', '1980s')
            ->where('groups.1.name', '1970s')
            ->where('records.data.0.displayTitle', 'Album 1981'));
    $this->get('/cache_failure?mode=artist')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', 'Cromagnon')->where('groups.1.name', 'Cro Magnon'));
});

it('groups latest additions by Brussels calendar day across pages, with newest records first', function () {
    $owner = socialUser('dated_shelf', 'everyone');
    socialRecord($owner, 'Before midnight', date: '2026-06-10 21:59:59', instance: 1);
    socialRecord($owner, 'After midnight', date: '2026-06-10 22:00:00', instance: 2);
    foreach (range(3, 39) as $instance) {
        socialRecord($owner, "Same day {$instance}", date: '2026-06-11 10:00:00', instance: $instance);
    }
    socialRecord($owner, 'Older addition', date: '2025-11-01 10:00:00', instance: 40);

    $first = $this->get('/dated_shelf')->assertOk();
    $second = $this->get('/dated_shelf?recordsPage=2')->assertOk();
    expect($first->inertiaProps('groups'))->toBe([
        ['name' => '2026-06-11', 'count' => 38, 'offset' => 0],
        ['name' => '2026-06-10', 'count' => 1, 'offset' => 38],
        ['name' => '2025-11-01', 'count' => 1, 'offset' => 39],
    ])->and(array_column($first->inertiaProps('records.data'), 'instanceId'))->toBe(range(39, 4))
        ->and(array_column($second->inertiaProps('records.data'), 'instanceId'))->toBe([3, 2, 1, 40])
        ->and(array_column($second->inertiaProps('records.data'), 'section'))->toBe(['2026-06-11', '2026-06-11', '2026-06-10', '2025-11-01']);
});

it('preserves collection search and multi-genre grouping across server pages', function () {
    $owner = socialUser('search_shelf', 'everyone');
    $copy = socialRecord($owner, 'Blue Room', instance: 1);
    app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'artist' => 'Comité Hypnotisé', 'artists' => ['Comité Hypnotisé'],
        'genres' => ['Jazz', 'Electronic', 'Jazz'], 'originalYear' => 1974, 'year' => 2025,
        'tracks' => [['title' => 'A Strange Morning']]]]);
    socialRecord($owner, 'Unrelated album', instance: 2);
    $result = $this->get('/search_shelf?mode=genre&q=comite+strange+1974')->assertOk();
    expect(array_column($result->inertiaProps('groups'), 'name'))->toBe(['Electronic', 'Jazz'])
        ->and(array_column($result->inertiaProps('groups'), 'count'))->toBe([1, 1])
        ->and(array_column($result->inertiaProps('records.data'), 'displayTitle'))->toBe(['Blue Room', 'Blue Room']);
    $this->get('/search_shelf?mode=decade&q=comite')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', '1970s')->has('records.data', 1));
    $this->get('/search_shelf?q=notfound')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 0)->has('groups', 0));
    app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'artist' => '808', 'artists' => ['808']]]);
    $this->get('/search_shelf?mode=artist&q=blue')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', '808')->where('records.data.0.section', '808'));
    app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'tracks' => [['title' => '100%_live!']]]]);
    $this->get('/search_shelf?'.http_build_query(['q' => '100%_live!']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1));
    $this->get('/search_shelf?'.http_build_query(['q' => '100%_live?']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
    $this->get('/search_shelf?'.http_build_query(['q' => '%']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1));
});

it('keeps natural title ordering and current effective dates when browsing artists and decades', function () {
    $owner = socialUser('release_order', 'everyone');
    foreach ([
        ['Album 10', 1974, '1974-06'],
        ['Album 2', 1974, '1974-06'],
        ['Stored date', null, '1981-02'],
        ['Conflicting date', 1979, '1965'],
        ['Unknown date', null, null],
        ['Decade boundary', 1980, '1980-01'],
    ] as $instance => [$title, $year, $date]) {
        $copy = socialRecord($owner, $title, instance: $instance + 1);
        $releaseId = 900000 + $instance;
        app(StoreCollectionRecordAction::class)->execute($copy, ['discogs_release_id' => $releaseId, 'payload' => [...$copy->payload, 'id' => $releaseId, 'originalYear' => $year]]);
        RecordReleaseDate::query()->create(['discogs_release_id' => $releaseId, 'original_release_date' => $date]);
    }

    $artists = $this->get('/release_order?mode=artist')->assertOk();
    expect(array_column($artists->inertiaProps('records.data'), 'displayTitle'))
        ->toBe(['Stored date', 'Decade boundary', 'Conflicting date', 'Album 2', 'Album 10', 'Unknown date']);
    $decades = $this->get('/release_order?mode=decade')->assertOk();
    expect(array_column($decades->inertiaProps('groups'), 'name'))->toBe(['1980s', '1970s', 'Unknown year'])
        ->and(array_column($decades->inertiaProps('records.data'), 'displayTitle'))
        ->toBe(['Decade boundary', 'Stored date', 'Album 2', 'Album 10', 'Conflicting date', 'Unknown date']);

    RecordReleaseDate::query()->whereKey(900002)->update(['original_release_date' => '1963']);
    $this->get('/release_order?mode=decade&q=1963')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.name', '1960s')->where('records.data.0.displayTitle', 'Stored date')->has('records.data', 1));
});

it('uses root profile URLs while retaining the old profile alias without redirects', function () {
    $owner = socialUser('canonical_owner', 'everyone');
    socialRecord($owner, 'Canonical record');
    expect(route('profile', $owner->handle))->toBe(url('/canonical_owner'));
    foreach (['/canonical_owner', '/people/canonical_owner'] as $path) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->component('social/ProfilePage'))->assertSee('Canonical record');
    }
    foreach (['/unknown_collector', '/ab', '/InvalidHandle', '/invalid-handle', '/'.str_repeat('a', 31)] as $path) {
        $this->get($path)->assertNotFound();
    }
    foreach (['/login', '/register', '/forgot-password'] as $path) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->component('social/AuthPage'));
    }
    $this->get('/settings')->assertRedirect('/login');
    $this->get('/feed')->assertRedirect('/login');
});

it('uses opaque owner-scoped record urls and aliases with the correct public preview', function () {
    $owner = socialUser('nice_urls', 'everyone');
    $other = socialUser('wrong_owner', 'everyone');
    $record = socialRecord($owner, 'A Blue Album!');
    $url = '/nice_urls/records/'.$record->public_id.'/george-harrison-a-blue-album';

    expect($record->url(false))->toBe($url);
    foreach ([$url, '/nice_urls?record='.$record->public_id, '/shelves/'.$record->public_id] as $path) {
        $this->get($path)->assertOk()
            ->assertSee('property="og:title" content="A Blue Album! by George Harrison"', false)
            ->assertSee('rel="canonical" href="'.url($url).'"', false);
    }
    $this->get('/wrong_owner/records/'.$record->public_id.'/george-harrison-a-blue-album')->assertNotFound();
    $this->get('/nice_urls/records/'.$record->public_id.'/an-old-title')->assertOk();
});

it('retains the background component for partial record navigation without exposing restricted data', function (string $component) {
    $owner = socialUser('partial_record', 'everyone');
    $viewer = socialUser('partial_viewer');
    $record = socialRecord($owner);
    $headers = [
        'X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => 'recordOverlay',
    ];

    $this->actingAs($viewer)->get($record->url(false).'?mode=artist&q=blue', $headers)->assertOk()
        ->assertJsonPath('component', $component)
        ->assertJsonPath('props.recordOverlay.record.id', $record->id)
        ->assertJsonMissingPath('props.recordOverlay.record.privateNote');
    $owner->update(['visibility' => 'only_me']);
    $this->get($record->url(false), $headers)->assertRedirect('/partial_record');
})->with(['social/ProfilePage', 'social/HomePage', 'social/CollectionsPage', 'social/FeedPage']);

it('preserves shelf browsing context in the record return link without allowing external destinations', function () {
    $owner = socialUser('browsingcollector', 'everyone');
    $record = socialRecord($owner);
    $browse = 'decade=1980s&q=Blue Notes';
    $this->get('/shelves/'.$record->public_id.'?'.http_build_query(['browse' => $browse]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('collectionUrl', url('/browsingcollector').'#'.$browse));
    $this->get('/shelves/'.$record->public_id.'?browse=https://example.com')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('collectionUrl', url('/browsingcollector').'#https://example.com'));
    $this->get('/shelves/'.$record->public_id.'?browse[]=invalid')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('collectionUrl', url('/browsingcollector')));
});

it('keeps the selected copy and social actions in its own cupboard without exposing owner-only data', function () {
    $owner = socialUser('modalowner', 'everyone');
    $viewer = socialUser('modalviewer');
    $record = socialRecord($owner, 'Selected public copy', instance: 500);
    socialRecord($viewer, 'Different private copy', instance: 500);
    $record->update(['private_note' => 'Owner-only modal note', 'shared_note' => 'Shared modal note']);
    foreach (range(1, 31) as $number) {
        $record->comments()->create(['user_id' => $viewer->id, 'body' => "Comment {$number}"]);
    }
    $url = '/modalowner?'.http_build_query(['record' => $record->public_id]);
    $response = $this->actingAs($viewer)->get($url)->assertOk()
        ->assertDontSee('Different private copy')->assertDontSee('Owner-only modal note')
        ->assertInertia(fn (Assert $page) => $page->component('social/ProfilePage')
            ->where('person.id', $owner->id)->has('records.data', 1)
            ->where('recordOverlay.record.id', $record->id)
            ->where('recordOverlay.record.sharedNote', 'Shared modal note')
            ->missing('recordOverlay.record.privateNote')
            ->has('recordOverlay.comments.data', 30));
    $next = $response->inertiaProps('recordOverlay.comments.next_page_url');
    $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/ProfilePage')->has('recordOverlay.comments.data', 1)
        ->where('recordOverlay.comments.data.0.body', 'Comment 31'));
    $this->from($url)->post('/shelves/'.$record->public_id.'/appreciations')->assertRedirect($url);
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('recordOverlay.appreciations', 1)->where('recordOverlay.appreciated', true));
    $this->from($url)->post('/shelves/'.$record->public_id.'/comments', ['body' => 'From the cupboard'])->assertRedirect($url);
    $this->get($next)->assertInertia(fn (Assert $page) => $page
        ->has('recordOverlay.comments.data', 2)->where('recordOverlay.comments.data.1.body', 'From the cupboard'));
    $owner->update(['visibility' => 'only_me']);
    $this->get($url)->assertRedirect('/modalowner');
    $this->get($next)->assertRedirect('/modalowner');
});

it('enforces the full collection visibility matrix on profiles and record pages', function (string $visibility, string $viewerKind, bool $allowed) {
    $owner = socialUser('owner', $visibility);
    $friend = socialUser('friend');
    $stranger = socialUser('stranger');
    socialFriends($owner, $friend);
    $record = socialRecord($owner);
    if ($viewerKind !== 'guest') {
        $this->actingAs(match ($viewerKind) {
            'owner' => $owner, 'friend' => $friend, default => $stranger
        });
    }
    $profile = $this->get(route('profile', $owner->handle));
    $response = $this->get('/shelves/'.$record->public_id);
    $readable = $this->get($record->url(false));
    $cover = $this->get('/shelves/'.$record->public_id.'/cover');
    $cupboard = $this->get('/owner?record='.$record->public_id);
    $elsewhere = $this->get('/collections?record='.$record->public_id);
    if ($allowed) {
        $profile->assertOk()->assertSee('Private Test Album')->assertInertia(fn (Assert $page) => $page
            ->where('person.name', 'Owner')->where('person.handle', 'owner')->where('visible', true)->has('records.data', 1));
        $response->assertOk()->assertSee('Private Test Album');
        $readable->assertOk()->assertInertia(fn (Assert $page) => $page->where('recordOverlay.record.id', $record->id));
        $cover->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        expect($profile->inertiaProps('records.data.0.cover'))->toBe('/shelves/'.$record->public_id.'/cover')
            ->and($response->inertiaProps('record.payload.cover'))->toBe($profile->inertiaProps('records.data.0.cover'));
        $cupboard->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('social/ProfilePage')->has('records.data', 1)
            ->where('recordOverlay.record.id', $record->id)
            ->where('recordOverlay.record.canEditNotes', $viewerKind === 'owner'));
        $elsewhere->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('social/CollectionsPage')->where('recordOverlay.record.id', $record->id));
    } else {
        $profile->assertDontSee('Private Test Album')->assertDontSee($owner->email);
        $cover->assertNotFound();
        if ($viewerKind === 'guest') {
            foreach ([$profile, $response, $cupboard, $elsewhere, $readable] as $denied) {
                $denied->assertRedirect('/login');
            }
        } else {
            $profile->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('visible', false)->where('records', null)->where('friendsOnly', $visibility === 'friends'));
            foreach ([$response, $cupboard, $elsewhere, $readable] as $denied) {
                $denied->assertRedirect('/owner')->assertDontSee('Private Test Album');
            }
        }
    }
    expect($profile->headers->get('Cache-Control'))->toContain('no-store', 'private');
})->with([
    ['only_me', 'guest', false], ['only_me', 'stranger', false], ['only_me', 'friend', false], ['only_me', 'owner', true],
    ['friends', 'guest', false], ['friends', 'stranger', false], ['friends', 'friend', true], ['friends', 'owner', true],
    ['everyone', 'guest', true], ['everyone', 'stranger', true], ['everyone', 'friend', true], ['everyone', 'owner', true],
]);

it('requires mutual acceptance, prevents self acceptance, and supports the complete request lifecycle', function () {
    $a = socialUser('alice', 'friends');
    $b = socialUser('bob', 'friends');
    $c = socialUser('carol');
    $record = socialRecord($b);
    $this->actingAs($a)->post('/friends/'.$a->public_id, ['action' => 'request'])->assertUnprocessable();
    $this->post('/friends/'.$b->public_id, ['action' => 'request'])->assertRedirect();
    $this->post('/friends/'.$b->public_id, ['action' => 'request'])->assertRedirect();
    $this->assertDatabaseCount('friendships', 1);
    $this->get('/shelves/'.$record->public_id)->assertRedirect('/bob');
    $this->post('/friends/'.$b->public_id, ['action' => 'accept'])->assertForbidden();
    $this->post('/logout');
    $this->actingAs($c)->post('/friends/'.$a->public_id, ['action' => 'accept'])->assertForbidden();
    $this->post('/logout');
    $this->actingAs($b)->post('/friends/'.$a->public_id, ['action' => 'request'])->assertRedirect();
    $this->get('/friends/manage')->assertInertia(fn (Assert $page) => $page
        ->where('connections.0.accepted_at', null)->where('connections.0.requested_by', $a->id)->where('people.data.0.id', $a->id));
    $this->post('/friends/'.$a->public_id, ['action' => 'accept'])->assertRedirect();
    $this->post('/logout');
    $this->actingAs($a)->get('/shelves/'.$record->public_id)->assertOk();
    $this->get('/friends/manage?q=bob')->assertSee('Bob')->assertDontSee('Carol');
    $this->post('/friends/'.$b->public_id, ['action' => 'unfriend'])->assertRedirect();
    $this->get('/shelves/'.$record->public_id)->assertRedirect('/bob');
    foreach (['cancel', 'decline'] as $action) {
        $this->post('/logout');
        $this->actingAs($a)->post('/friends/'.$b->public_id, ['action' => 'request']);
        $this->post('/logout');
        $this->actingAs($action === 'cancel' ? $a : $b)->post('/friends/'.($action === 'cancel' ? $b->public_id : $a->public_id), ['action' => $action])->assertRedirect();
        $this->assertDatabaseCount('friendships', 0);
    }
    $activities = Activity::query()->where('log_name', 'social')->orderBy('id')->get();
    expect($activities->pluck('event')->all())->toBe([
        'friend_request_sent', 'friend_request_accepted', 'unfriended',
        'friend_request_sent', 'friend_request_cancelled', 'friend_request_sent', 'friend_request_declined',
    ])->and($activities->pluck('causer_id')->all())->toBe([$a->id, $b->id, $a->id, $a->id, $a->id, $a->id, $b->id])
        ->and($activities->pluck('subject_id')->all())->toBe([$b->id, $a->id, $b->id, $b->id, $b->id, $b->id, $a->id]);
});

it('finds an exact username with the displayed at prefix and optional whitespace', function (string $input, string $handle) {
    $viewer = socialUser('search_viewer');
    $person = socialUser($handle);
    $this->actingAs($viewer)->get('/friends/manage?'.http_build_query(['q' => $input]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('query', $handle)->has('people.data', 1)->where('people.data.0.id', $person->id));
})->with([
    ['bob', 'bob'], ['@BOB', 'bob'], ['  @Bob  ', 'bob'],
    ['@abcdefghijklmnopqrstuvwxyz1234', 'abcdefghijklmnopqrstuvwxyz1234'],
]);

it('prioritizes importing a first collection without empty feed and request clutter', function () {
    $viewer = socialUser('newcollector');
    $this->actingAs($viewer)->get('/friends')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('social/FeedPage')
            ->where('collectionCount', 0)->where('auth.user.discogsConnected', false)
            ->where('auth.user.importedAt', null)->has('friends', 0)->missing('incomingRequests'));
    foreach (['/settings', '/newcollector'] as $path) {
        $this->get($path)->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.collectionCount', 0)->where('auth.user.importedAt', null));
    }

    $requester = socialUser('requester');
    Friendship::query()->create([
        'user_low' => min($viewer->id, $requester->id), 'user_high' => max($viewer->id, $requester->id),
        'requested_by' => $requester->id,
    ]);
    $this->get('/friends')->assertInertia(fn (Assert $page) => $page
        ->where('collectionCount', 0)->missing('incomingRequests'));

    $viewer->update(['discogs_token' => 'test-token', 'sync_status' => 'Syncing']);
    $this->get('/friends')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.syncStatus', 'Syncing')->where('auth.user.discogsConnected', true));
});

it('shows collection counts on the friends page only while the viewer has access', function (string $visibility, bool $accepted, int $count, ?int $expected) {
    $viewer = socialUser('count_viewer');
    $owner = socialUser('count_owner', $visibility);
    $connection = socialFriends($owner, $viewer);
    if (! $accepted) {
        $connection->update(['accepted_at' => null]);
    }
    for ($instance = 1; $instance <= $count; $instance++) {
        socialRecord($owner, instance: $instance, activity: false);
    }

    $this->actingAs($viewer)->get('/friends/manage')->assertInertia(fn (Assert $page) => $page
        ->has('people.data', 1)->where('people.data.0.id', $owner->id)
        ->where('people.data.0.recordCount', $expected));
    $connection->delete();
    $this->get('/friends/manage?q=count_owner')->assertInertia(fn (Assert $page) => $page
        ->where('people.data.0.recordCount', $visibility === 'everyone' ? $count : null));
})->with([
    'public friend' => ['everyone', true, 3, 3],
    'friends collection' => ['friends', true, 2, 2],
    'private friend' => ['only_me', true, 4, null],
    'pending friends collection' => ['friends', false, 5, null],
    'pending public collection' => ['everyone', false, 1, 1],
    'empty visible collection' => ['friends', true, 0, 0],
]);

it('keeps the normal feed after importing even an empty collection', function (bool $hasRecords) {
    $viewer = socialUser('returningcollector');
    $hasRecords
        ? socialRecord($viewer)
        : $viewer->update(['collection_imported_at' => now()]);
    $this->actingAs($viewer)->get('/friends')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('collectionCount', $hasRecords ? 1 : 0)->where('auth.user.profileUrl', '/returningcollector')
        ->where('auth.user.importedAt', $viewer->collection_imported_at?->toIso8601String())
        ->has('friends', 0)->missing('incomingRequests'));
})->with([true, false]);

it('orders friends releases by original addition date and respects privacy and unfriending dynamically', function () {
    $viewer = socialUser('viewer');
    $friend = socialUser('friend', 'friends');
    $stranger = socialUser('stranger', 'everyone');
    $connection = socialFriends($friend, $viewer);
    socialRecord($friend, 'Older addition', true, '2026-10-15 12:00:00', 1);
    socialRecord($friend, 'Newer addition', true, '2026-10-16 12:00:00', 2);
    socialRecord($friend, 'Initial import', false, '2026-10-17 12:00:00', 3);
    socialRecord($stranger, 'Not a friend');
    $this->actingAs($viewer)->get('/friends')->assertSeeInOrder(['Initial import', 'Newer addition', 'Older addition'])->assertDontSee('Not a friend');
    $friend->update(['visibility' => 'only_me']);
    $this->get('/friends')->assertDontSee('Newer addition')->assertDontSee('Initial import');
    $friend->update(['visibility' => 'everyone']);
    $connection->delete();
    $this->get('/friends')->assertDontSee('Newer addition')->assertDontSee('Initial import');
});

it('shows only each accepted friends five latest records regardless of activity or import timing', function (bool $importAfterAcceptance) {
    $viewer = socialUser('welcomeviewer');
    $friend = socialUser('welcomefriend', 'friends');
    $other = socialUser('otherfriend', 'everyone');
    $pending = socialFriends($friend, $viewer);
    $pending->update(['accepted_at' => null]);
    socialFriends($viewer, $other);
    socialRecord($other, 'Other friend latest', false, '2026-10-18 12:00:00');

    $this->actingAs($viewer)->get('/friends')->assertInertia(fn (Assert $page) => $page
        ->has('friends', 1)->has('friends.0.records', 1));

    if ($importAfterAcceptance) {
        $this->post('/friends/'.$friend->public_id, ['action' => 'accept'])->assertRedirect();
    }

    foreach (range(1, 7) as $instance) {
        socialRecord($friend, 'Imported record '.$instance, false, '2026-10-17 12:00:00', $instance);
    }
    socialRecord($friend, 'Older activity', true, '2026-10-01 12:00:00', 8);

    if (! $importAfterAcceptance) {
        $this->get('/friends')->assertDontSee('Imported record')->assertDontSee('Older activity');
        $this->post('/friends/'.$friend->public_id, ['action' => 'accept'])->assertRedirect();
    }

    $response = $this->get('/friends')->assertDontSee('Older activity')
        ->assertInertia(fn (Assert $page) => $page->has('friends', 2)->has('friends.0.records', 1)->has('friends.1.records', 5));
    expect(array_column($response->inertiaProps('friends.1.records'), 'displayTitle'))->toBe([
        'Imported record 7', 'Imported record 6', 'Imported record 5', 'Imported record 4', 'Imported record 3',
    ]);
    expect($friend->records()->where('is_activity', true)->pluck('instance_id')->all())->toBe([8]);
    expect(SocialNotification::query()->pluck('kind')->all())->toBe(['friend_accepted']);
})->with([false, true]);

it('allows signed-in viewers to appreciate once, comment safely, and apply owner moderation', function () {
    $owner = socialUser('owner', 'everyone');
    $author = socialUser('author');
    $other = socialUser('other');
    $record = socialRecord($owner);
    $this->post('/shelves/'.$record->public_id.'/appreciations')->assertRedirect('/login');
    $this->actingAs($author)->post('/shelves/'.$record->public_id.'/appreciations')->assertRedirect();
    $this->post('/shelves/'.$record->public_id.'/appreciations')->assertRedirect();
    $this->assertDatabaseCount('appreciations', 1);
    $this->assertDatabaseCount('social_notifications', 1);
    $this->post('/shelves/'.$record->public_id.'/comments', ['body' => '<script>alert(1)</script>'])->assertRedirect();
    $comment = $record->comments()->firstOrFail();
    $this->get('/shelves/'.$record->public_id)->assertInertia(fn (Assert $page) => $page->where('comments.data.0.body', '<script>alert(1)</script>'))
        ->assertDontSee('<script>alert(1)</script>', false);
    $this->post('/shelves/'.$record->public_id.'/comments', ['body' => str_repeat('x', 2001)])->assertSessionHasErrors('body');
    $this->post('/logout');
    $this->actingAs($other)->delete('/shelves/'.$record->public_id.'/comments/'.$comment->public_id)->assertForbidden();
    $this->post('/logout');
    $another = socialRecord($owner, 'Another', instance: 124);
    $this->actingAs($owner)->delete('/shelves/'.$another->public_id.'/comments/'.$comment->public_id)->assertNotFound();
    $this->delete('/shelves/'.$record->public_id.'/comments/'.$comment->public_id)->assertRedirect();
    $owner->update(['comments_enabled' => false]);
    $this->post('/logout');
    $this->actingAs($author)->post('/shelves/'.$record->public_id.'/comments', ['body' => 'No'])->assertForbidden();
    $owner->update(['comments_enabled' => true]);
    $this->post('/shelves/'.$record->public_id.'/comments', ['body' => 'Remove me']);
    $comment = $record->comments()->firstOrFail();
    $owner->update(['visibility' => 'only_me']);
    $this->post('/shelves/'.$record->public_id.'/comments', ['body' => 'Denied'])->assertNotFound();
    $this->post('/shelves/'.$record->public_id.'/appreciations')->assertNotFound();
    $this->delete('/shelves/'.$record->public_id.'/comments/'.$comment->public_id)->assertRedirect();
    $this->assertDatabaseCount('comments', 0);
});

it('shows only the recipient’s currently visible notifications and scopes read updates', function () {
    $viewer = socialUser('viewer');
    $owner = socialUser('owner', 'friends');
    socialFriends($viewer, $owner);
    $record = socialRecord($owner, 'Visible notification album');
    app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload, 'artist' => 'Notification Artist'], 'private_note' => 'Never in a notification']);
    SocialNotification::send($viewer, $owner, 'comment', $record);
    SocialNotification::send($owner, $viewer, 'friend_accepted');
    $this->actingAs($viewer)->get('/notifications')->assertRedirect('/friends?notifications=1');
    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('notificationCount', 1));
    $this->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertJsonCount(1, 'notifications.data')->assertJsonPath('notifications.data.0.url', $record->url(false).'#comments')
        ->assertJsonPath('notifications.data.0.text', 'commented on your record.')
        ->assertJsonPath('notifications.data.0.record.title', 'Visible notification album')
        ->assertJsonPath('notifications.data.0.record.artist', 'Notification Artist')
        ->assertJsonPath('notifications.data.0.actor.id', $owner->id)->assertJsonPath('unreadCount', 1)
        ->assertDontSee($owner->email)->assertDontSee('Never in a notification')->assertDontSee('accepted your friend request');
    $owner->update(['visibility' => 'only_me']);
    $this->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertDontSee('Visible notification album')->assertDontSee('commented on your record')
        ->assertJsonCount(0, 'notifications.data')->assertJsonPath('unreadCount', 0);
    $this->post('/notifications/read')->assertRedirect();
    expect(SocialNotification::query()->whereNotNull('read_at')->count())->toBe(0);
    $owner->update(['visibility' => 'friends']);
    $this->post('/notifications/read', [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNoContent();
    $this->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertJsonPath('notifications.data.0.url', $record->url(false).'#comments')
        ->assertJsonPath('notifications.data.0.unread', false)->assertJsonPath('unreadCount', 0);
    expect(SocialNotification::query()->where('user_id', $viewer->id)->first()->read_at)->not->toBeNull()
        ->and(SocialNotification::query()->where('user_id', $owner->id)->first()->read_at)->toBeNull();
});

it('keeps older notifications accessible in the popover', function () {
    $viewer = socialUser('viewer');
    $actor = socialUser('actor');
    $actor->update(['google_avatar_url' => 'https://lh3.googleusercontent.com/actor-photo', 'google_id' => 'hidden-subject']);
    SocialNotification::send($viewer, $actor, 'friend_accepted');
    foreach (range(1, 15) as $index) {
        SocialNotification::send($viewer, $actor, 'friend_request');
    }

    $this->actingAs($viewer)->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertSee('sent you a friend request')->assertDontSee('accepted your friend request')
        ->assertJsonPath('notifications.data.0.actor.avatarUrl', $actor->google_avatar_url)
        ->assertDontSee('hidden-subject')->assertDontSee($actor->email)
        ->assertSee('page=2', false)->assertJsonPath('unreadCount', 16)->assertJsonCount(15, 'notifications.data');
    $this->get('/notifications?page=2', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertSee('accepted your friend request')->assertDontSee('sent you a friend request')
        ->assertJsonPath('notifications.data.0.actor.avatarUrl', $actor->google_avatar_url);
    $actor->update(['google_avatar_url' => null]);
    $this->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertJsonPath('notifications.data.0.actor.avatarUrl', null);
    $this->post('/notifications/read', [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNoContent();
    $this->get('/notifications?page=2', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertJsonPath('notifications.data.0.unread', false)->assertJsonPath('unreadCount', 0);
    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('notificationCount', 0));
    $this->assertDatabaseCount('social_notifications', 16);
});

it('clears all of the recipient’s notifications without changing friendships or other recipients', function () {
    $viewer = socialUser('viewer');
    $actor = socialUser('actor');
    $friendship = socialFriends($viewer, $actor);
    $hidden = socialRecord($actor);
    SocialNotification::send($viewer, $actor, 'comment', $hidden);
    foreach (range(1, 16) as $index) {
        SocialNotification::send($viewer, $actor, 'friend_accepted');
    }
    SocialNotification::query()->where('user_id', $viewer->id)->first()->update(['read_at' => now()]);
    SocialNotification::send($actor, $viewer, 'friend_request');

    $this->delete('/notifications')->assertRedirect('/login');
    $this->actingAs($viewer)->delete('/notifications', [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNoContent();
    $this->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertJsonCount(0, 'notifications.data')->assertJsonPath('unreadCount', 0);
    $this->assertDatabaseCount('social_notifications', 1);
    $this->assertDatabaseHas('social_notifications', ['user_id' => $actor->id, 'read_at' => null]);
    $this->assertModelExists($friendship);
    $this->assertModelExists($hidden);
});

it('expires read and unread notifications at seven days and prunes only expired rows', function () {
    $this->freezeTime();
    $viewer = socialUser('viewer');
    $actor = socialUser('actor');
    foreach ([now()->subDays(8), now()->subDays(7), now()->subDays(7)->addSecond()] as $createdAt) {
        foreach ([null, now()] as $readAt) {
            SocialNotification::query()->create([
                'user_id' => $viewer->id, 'actor_id' => $actor->id, 'kind' => 'friend_accepted',
                'created_at' => $createdAt, 'read_at' => $readAt,
            ]);
        }
    }

    $this->actingAs($viewer)->get('/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertJsonCount(2, 'notifications.data')->assertJsonPath('unreadCount', 1);
    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('notificationCount', 1));
    $this->artisan('model:prune', ['--model' => [SocialNotification::class]])->assertSuccessful();
    $this->assertDatabaseCount('social_notifications', 2);
    expect(SocialNotification::query()->toBase()->pluck('created_at')->unique()->all())->toBe([now()->subDays(7)->addSecond()->toDateTimeString()]);
});

it('isolates legacy collection copies, snapshot fallback, and all legacy URLs from other owners', function () {
    $owner = socialUser('private');
    $record = socialRecord($owner, 'Never leak me', instance: 2189134863);
    $this->get('/collection')->assertOk()->assertDontSee('Never leak me');
    $publicId = legacyRecordPublicId(2189134863);
    $this->getJson('/api/records/'.$publicId)->assertJsonPath('title', 'Shebang');
    $this->get('/record/'.$publicId.'/oren-ambarchi-shebang')->assertOk()->assertDontSee('Never leak me');
    $this->get('/record/'.$publicId.'/image.png')->assertRedirect();
    $this->get('/share/image.png')->assertRedirect();
    socialRecord($owner, 'Unknown legacy copy', instance: 999999);
    $this->getJson('/api/records/999999')->assertNotFound();
    User::freek()->update(['collection_imported_at' => now()]);
    $this->getJson('/api/records/'.$publicId)->assertNotFound();
    expect($record->fresh())->not->toBeNull();
});

it('serves explicit safe people and viewer contracts on Inertia visits without serializing credentials', function () {
    $owner = socialUser('contract_owner', 'everyone');
    $viewer = socialUser('contract_viewer');
    foreach ([$owner, $viewer] as $person) {
        $person->update([
            'google_id' => 'google-subject-'.$person->id,
            'google_avatar_url' => 'https://lh3.googleusercontent.com/private-google-photo-'.$person->id,
            'discogs_token' => 'discogs-access-'.$person->id,
            'discogs_secret' => 'discogs-secret-'.$person->id,
            'remember_token' => 'remember-secret-'.$person->id,
        ]);
    }
    socialFriends($owner, $viewer);
    $record = socialRecord($owner);
    app(StoreCollectionRecordAction::class)->execute($record, [
        'private_note' => 'contract-private-note', 'shared_note' => 'contract-shared-note',
        'payload' => [...$record->payload, 'private_note' => 'injected-note', 'oauth_token' => 'injected-token',
            'tracks' => [[...$record->payload['tracks'][0], 'oauth_token' => 'injected-track-token']]],
    ]);
    $comment = $record->comments()->create(['user_id' => $viewer->id, 'body' => 'A public comment']);
    $headers = [
        'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
    ];
    $personKeys = ['id', 'publicId', 'name', 'handle', 'profileUrl', 'avatarUrl'];

    foreach (['/community', '/collections', '/contract_owner', '/friends', '/friends/manage', '/settings', '/shelves/'.$record->public_id] as $path) {
        $response = $this->actingAs($viewer)->get($path, $headers)->assertOk()->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('props.auth.user.email', $viewer->email)
            ->assertJsonPath('props.auth.user.googleConnected', true)->assertJsonPath('props.auth.user.discogsConnected', true)
            ->assertJsonPath('props.auth.user.avatarUrl', $viewer->google_avatar_url)
            ->assertDontSee($owner->email)->assertDontSee('contract-private-note')
            ->assertDontSee('google-subject-')->assertDontSee('discogs-access-')->assertDontSee('discogs-secret-')
            ->assertDontSee('remember-secret-')->assertDontSee($viewer->password)->assertDontSee($owner->password)
            ->assertDontSee('injected-note')->assertDontSee('injected-token')->assertDontSee('injected-track-token');
        expect(array_keys($response->json('props.auth.user')))->toBe([
            ...$personKeys, 'email', 'verified', 'visibility', 'legacyOwner', 'commentsEnabled', 'digestEnabled',
            'googleConnected', 'discogsConnected', 'discogsUsername', 'importedAt', 'lastSyncedAt', 'syncStatus', 'syncTotal', 'collectionCount',
        ])->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    }

    $profile = $this->get('/contract_owner', $headers)->assertJsonPath('props.visible', true)
        ->assertJsonPath('props.person.avatarUrl', $owner->google_avatar_url);
    $this->get('/friends/manage', $headers)->assertJsonPath('props.people.data.0.avatarUrl', $owner->google_avatar_url);
    expect(array_keys($profile->json('props.person')))->toBe($personKeys);
    $release = $this->get('/shelves/'.$record->public_id, $headers)
        ->assertJsonMissingPath('props.record.privateNote')->assertJsonPath('props.record.canEditNotes', false)
        ->assertJsonPath('props.record.sharedNote', 'contract-shared-note')
        ->assertJsonPath('props.record.owner.avatarUrl', $owner->google_avatar_url)
        ->assertJsonPath('props.comments.data.0.user.avatarUrl', $viewer->google_avatar_url)
        ->assertJsonPath('props.comments.data.0.id', $comment->id)->assertJsonPath('props.comments.data.0.canDelete', true);
    expect(array_keys($release->json('props.record.owner')))->toBe($personKeys)
        ->and(array_keys($release->json('props.comments.data.0')))->toBe(['id', 'publicId', 'body', 'user', 'canDelete'])
        ->and(array_keys($release->json('props.comments.data.0.user')))->toBe($personKeys)
        ->and(array_keys($release->json('props.comments')))->toBe(['data', 'prev_page_url', 'next_page_url']);

    $owner->update(['visibility' => 'only_me']);
    $this->get('/contract_owner', $headers)->assertJsonPath('props.visible', false)->assertJsonPath('props.records', null);
    $this->get('/shelves/'.$record->public_id, $headers)->assertRedirect('/contract_owner');
    $this->actingAs($owner)->get('/shelves/'.$record->public_id, $headers)
        ->assertJsonPath('props.record.privateNote', 'contract-private-note')->assertJsonPath('props.record.canEditNotes', true)
        ->assertJsonPath('props.comments.data.0.canDelete', true);
});

it('redirects Inertia forms back with validation errors and refreshed props instead of JSON or empty responses', function () {
    $viewer = socialUser('forms_viewer');
    $record = socialRecord($viewer);
    $headers = [
        'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
    ];
    $this->actingAs($viewer)->from('/settings')->patch('/settings', ['handle' => 'ab'], $headers)
        ->assertStatus(303)->assertRedirect('/settings')->assertSessionHasErrors(['handle', 'visibility']);
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get('/settings', $headers)->assertJsonPath('component', 'social/SettingsPage')
        ->assertJsonStructure(['props' => ['errors' => ['handle', 'visibility']]])->assertJsonPath('props.auth.user.handle', 'forms_viewer');
    $this->from('/shelves/'.$record->public_id)->patch('/shelves/'.$record->public_id.'/notes', ['shared_note' => str_repeat('x', 5001)], $headers)
        ->assertStatus(303)->assertRedirect('/shelves/'.$record->public_id)->assertSessionHasErrors(['shared_note', 'private_note']);
    $this->from('/forms_viewer')->patch('/collection/visibility', ['visibility' => 'everyone'], $headers)
        ->assertStatus(303)->assertRedirect('/forms_viewer');
    $this->get('/forms_viewer', $headers)->assertJsonPath('props.auth.user.visibility', 'everyone');
    $actor = socialUser('forms_actor');
    SocialNotification::send($viewer, $actor, 'friend_request');
    $this->from('/friends')->post('/notifications/read', [], $headers)->assertRedirect('/friends');
    $this->get('/friends', $headers)->assertJsonPath('props.notificationCount', 0)
        ->assertJsonPath('props.flash.status', 'Notifications marked as read.');
});

it('takes guests through login and returns to the originally requested private page', function (string $page) {
    $owner = socialUser('locked_owner', 'friends');
    $viewer = socialUser('locked_viewer');
    socialFriends($owner, $viewer);
    $record = socialRecord($owner, 'Members-only album');
    $url = match ($page) {
        'profile' => '/locked_owner',
        'alias' => '/people/locked_owner',
        'record' => '/shelves/'.$record->public_id,
        'overlay' => '/locked_owner?record='.$record->public_id,
        'elsewhere' => '/collections?record='.$record->public_id,
        'home' => '/?record='.$record->public_id,
    };

    $this->get($url)->assertRedirect('/login')->assertSessionHas('url.intended', url('/').$url)
        ->assertDontSee('Members-only album');
    $this->get('/login')->assertOk()->assertDontSee('Members-only album');
    $this->post('/login', ['email' => $viewer->email, 'password' => 'a-long-demo-password'])->assertRedirect(url('/').$url);
    if ($page === 'home') {
        $this->get($url)->assertRedirect('/shelves/'.$record->public_id);
        $url = '/shelves/'.$record->public_id;
    }
    $this->get($url)->assertOk()->assertSee('Members-only album');
})->with(['profile', 'alias', 'record', 'overlay', 'elsewhere', 'home']);

it('offers the current friendship state on a locked collection and unlocks only after acceptance', function () {
    $owner = socialUser('request_owner', 'friends');
    $viewer = socialUser('request_viewer');
    $record = socialRecord($owner, 'Do not leak this record');
    $record->update(['shared_note' => 'Hidden shared note', 'private_note' => 'Hidden private note']);

    $this->actingAs($viewer)->get('/request_owner')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('visible', false)->where('friendsOnly', true)
            ->where('friendship', null)->where('records', null)->where('groups', [])->where('recordOverlay', null))
        ->assertDontSee('Do not leak this record')->assertDontSee('Hidden shared note')->assertDontSee('Hidden private note');
    $this->from('/request_owner')->post('/friends/'.$owner->public_id, ['action' => 'request'])->assertRedirect('/request_owner');
    $this->get('/request_owner')->assertInertia(fn (Assert $page) => $page
        ->where('visible', false)->where('friendship.requested_by', $viewer->id)->where('friendship.accepted_at', null));
    $this->get('/shelves/'.$record->public_id)->assertRedirect('/request_owner');

    $this->post('/logout');
    $this->actingAs($owner)->get('/request_viewer')->assertInertia(fn (Assert $page) => $page
        ->where('friendsOnly', false)->where('visible', false));
    $viewer->update(['visibility' => 'friends']);
    $this->get('/request_viewer')->assertInertia(fn (Assert $page) => $page
        ->where('friendship.requested_by', $viewer->id)->where('friendsOnly', true)->where('visible', false));
    $this->from('/request_viewer')->post('/friends/'.$viewer->public_id, ['action' => 'accept'])->assertRedirect('/request_viewer');
    $this->get('/request_viewer')->assertInertia(fn (Assert $page) => $page->where('visible', true));
    $this->post('/logout');
    $this->actingAs($viewer)->get('/shelves/'.$record->public_id)->assertOk()->assertSee('Do not leak this record');

    $owner->update(['visibility' => 'only_me']);
    $this->get('/shelves/'.$record->public_id)->assertRedirect('/request_owner');
    $this->get('/request_owner')->assertInertia(fn (Assert $page) => $page
        ->where('visible', false)->where('friendsOnly', false)->where('records', null));
});

it('limits friend actions per signed-in user and allows requests again after the window', function () {
    $viewer = socialUser('limited_viewer');
    $otherViewer = socialUser('other_limited_viewer');
    $owner = socialUser('limited_owner', 'friends');
    $this->actingAs($viewer);
    foreach (range(1, 30) as $attempt) {
        $this->from('/friends')->post('/friends/'.$owner->public_id, ['action' => 'request'])->assertRedirect('/friends');
    }
    $this->post('/friends/'.$owner->public_id, ['action' => 'request'])->assertStatus(429)->assertHeader('Retry-After');
    $this->assertDatabaseCount('friendships', 1);
    $this->post('/logout');
    $this->actingAs($otherViewer)->from('/friends')->post('/friends/'.$owner->public_id, ['action' => 'request'])->assertRedirect('/friends');
    $this->post('/logout');
    $this->travel(61)->seconds();
    $this->actingAs($viewer)->from('/friends')->post('/friends/'.$owner->public_id, ['action' => 'request'])->assertRedirect('/friends');
});
