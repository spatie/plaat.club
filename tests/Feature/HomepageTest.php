<?php

use App\Actions\Collections\StoreCollectionRecordAction;
use App\Models\Friendship;
use App\Models\RecordList;
use App\Models\User;
use App\Support\RecordCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('welcomes visitors with linked public artwork and private response headers', function () {
    $record = socialRecord(User::freek(), 'A real public record');
    $response = $this->get(route('home'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('social/HomePage')
        ->where('artwork.0.cover', $record->payload['cover'])
        ->where('artwork.0.url', $record->url(false))
        ->missing('collectors'));

    expect(route('home'))->toBe(url('/'))
        ->and(route('collection'))->toBe(url('/collection'))
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->get('Cache-Control'))->not->toContain('s-maxage', 'public');
});

it('features hand-picked public lists in editorial order and respects privacy immediately', function (string $path) {
    $owner = socialUser('featured_curator');
    $first = RecordList::query()->create([
        'user_id' => $owner->id, 'public_id' => '7dd4b401-53f4-4c32-a2c3-e9af74b7480f',
        'name' => 'Thom Yorke’s Desert Island Discs', 'description' => 'From the BBC programme.', 'visibility' => 'everyone',
    ]);
    $second = RecordList::query()->create([
        'user_id' => $owner->id, 'public_id' => '52249783-9141-44ba-a9c2-879ed0077c4a',
        'name' => 'Nick Cave’s Desert Island Discs', 'visibility' => 'everyone',
    ]);
    $copy = socialRecord($owner, 'A selected release');
    $first->entries()->create(['release_id' => $copy->discogs_release_id, 'position' => 0, 'private_note' => 'Not for discovery']);
    RecordList::query()->create(['user_id' => $owner->id, 'name' => 'An ordinary public list', 'visibility' => 'everyone']);

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('featuredLists', 2)
        ->where('featuredLists.0.publicId', $first->public_id)
        ->where('featuredLists.0.description', 'From the BBC programme.')
        ->where('featuredLists.0.count', 1)
        ->where('featuredLists.0.covers.0', route('lists.entries.cover', [$first, $first->entries()->first()], false))
        ->where('featuredLists.1.publicId', $second->public_id))
        ->assertDontSee('Not for discovery');

    foreach (['friends', 'only_me'] as $visibility) {
        $first->update(['visibility' => $visibility]);
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('featuredLists', 1)->where('featuredLists.0.publicId', $second->public_id));
    }
    $second->delete();
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->has('featuredLists', 0));

    $viewer = socialUser('featured_friend');
    socialFriends($owner, $viewer);
    $first->update(['visibility' => 'friends']);
    $this->actingAs($viewer)->get('/collections')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('featuredLists', 0));
})->with(['/', '/collections', '/collections?tab=lists']);

it('uses only live public records for linked artwork and never falls back to the bundled snapshot', function (string $path) {
    $friendsOwner = socialUser('friendsonly', 'friends');
    if ($path === '/verify-email') {
        $viewer = socialUser('unverifiedviewer');
        socialFriends($friendsOwner, $viewer);
        socialRecord($viewer, 'My private album');
        $this->actingAs($viewer);
    }
    socialRecord(socialUser('hiddenowner'), 'Private album');
    socialRecord($friendsOwner, 'Friends-only album');
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->has('artwork', 0));

    $owner = socialUser('openowner', 'everyone');
    $records = collect(app(RecordCollection::class)->snapshot())->unique('cover')->take(28)
        ->values()->map(function (array $payload, int $index) use ($owner) {
            $record = socialRecord($owner, $payload['displayTitle'], instance: $index + 1);
            app(StoreCollectionRecordAction::class)->execute($record, ['payload' => $payload]);

            return $record;
        });
    $response = $this->get($path)->assertOk();
    $artwork = collect($response->inertiaProps('artwork'));
    $expectedCount = $path === '/' ? 6 : 12;
    expect($artwork)->toHaveCount($expectedCount)->and($artwork->pluck('cover')->unique())->toHaveCount($expectedCount);
    foreach ($artwork as $item) {
        $record = $records->firstWhere('payload.cover', $item['cover']);
        expect($record)->not->toBeNull()
            ->and($item['url'])->toBe($record->url(false))
            ->and($item['title'])->toBe($record->payload['artist'].' — '.$record->payload['displayTitle']);
    }
    $link = $artwork->first()['url'];
    $this->get($link)->assertOk();
    $selected = $records->first(fn ($record) => $record->url(false) === $link);
    $this->get($path.'?record='.$selected->public_id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('recordOverlay.record.id', $selected->id)
        ->where('recordOverlay.record.payload.displayTitle', $selected->payload['displayTitle'])
        ->missing('recordOverlay.record.privateNote'));
    foreach (['friends', 'only_me'] as $visibility) {
        $owner->update(['visibility' => $visibility]);
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->has('artwork', 0));
        $destination = $path === '/verify-email' ? '/openowner' : '/login';
        $this->get($link)->assertRedirect($destination);
        $this->get($path.'?record='.$selected->public_id)->assertRedirect($destination);
    }
})->with(['/', '/login', '/register', '/forgot-password', '/reset-password/test-token', '/verify-email']);

it('previews nonempty public collections with their latest additions first', function () {
    socialUser('aaaempty', 'everyone');
    $expected = [];
    foreach (['delta', 'charlie', 'bravo', 'alpha'] as $handle) {
        $owner = socialUser($handle, 'everyone');
        $records = [];
        foreach ([3, 7, 1, 9, 5, 7, 2, 4] as $index => $day) {
            $records[] = socialRecord($owner, date: "2026-10-{$day} 12:00:00", instance: $index + 1);
        }
        $expected[$handle] = array_map(fn ($index) => $records[$index]->url(false), [3, 5, 1, 4, 7, 0]);
    }

    $collectors = $this->get('/collections')->assertOk()->inertiaProps('collectors.data');
    expect(array_column($collectors, 'handle'))->toBe(['alpha', 'bravo', 'charlie', 'delta']);
    foreach ($collectors as $collector) {
        expect($collector['recordCount'])->toBe(8)
            ->and(array_column($collector['records'], 'url'))->toBe($expected[$collector['handle']])
            ->and(array_keys($collector))->toBe(['id', 'publicId', 'name', 'handle', 'profileUrl', 'avatarUrl', 'recordCount', 'records']);
    }
});

it('paginates public collections alphabetically without including empty shelves', function () {
    socialUser('aaaempty', 'everyone');
    foreach (range(1, 13) as $index) {
        socialRecord(socialUser(sprintf('collector%02d', $index), 'everyone'));
    }

    $response = $this->get(route('collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/CollectionsPage')->has('collectors.data', 12)
        ->where('collectors.data.0.handle', 'collector01')->where('collectors.data.11.handle', 'collector12')
        ->where('collectors.prev_page_url', null)->where('collectors.next_page_url', url('/collections?page=2')));
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->get('/collections?page=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('collectors.data', 1)->where('collectors.data.0.handle', 'collector13')->where('collectors.next_page_url', null));
});

it('removes collections and their artwork from discovery immediately when privacy changes', function (string $visibility) {
    $owner = socialUser('discoverable', 'everyone');
    $viewer = socialUser('discoveryfriend');
    socialFriends($owner, $viewer);
    $record = socialRecord($owner, 'Discoverable record');
    app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload, 'cover' => '/images/discovery-only-cover.jpg']]);

    foreach (['/', '/collections'] as $path) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where($path === '/' ? 'artwork.0.cover' : 'collectors.data.0.records.0.cover', '/images/discovery-only-cover.jpg'));
    }
    $owner->update(['visibility' => $visibility]);
    foreach (['/', '/collections'] as $path) {
        $this->get($path)->assertOk()->assertDontSee('discoverable')->assertDontSee('Discoverable record')
            ->assertInertia(function (Assert $page) use ($path): void {
                $page->has($path === '/' ? 'artwork' : 'collectors.data', 0);
            });
    }
    $this->actingAs($viewer)->get('/collections')->assertOk()->assertDontSee('discoverable')
        ->assertDontSee('Discoverable record')->assertDontSee('/images/discovery-only-cover.jpg')
        ->assertInertia(fn (Assert $page) => $page->has('collectors.data', 0));
})->with(['only_me', 'friends']);

it('sends signed-in visitors to verification or their own collection without caching', function (bool $verified, string $destination) {
    $user = socialUser('homevisitor');
    if (! $verified) {
        $user->forceFill(['email_verified_at' => null])->save();
    }

    $response = $this->actingAs($user)->get('/')->assertRedirect($destination);

    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public', 's-maxage');
})->with([[true, '/homevisitor'], [false, '/verify-email']]);

it('keeps friend requests off the feed and available on the friends page', function () {
    $viewer = socialUser('homeviewer');
    $requester = socialUser('homerequester');
    $other = socialUser('unrelatedrequester');
    $stranger = socialUser('unrelatedrecipient');
    foreach (range(1, 3) as $instance) {
        socialRecord($viewer, instance: $instance);
    }
    Friendship::query()->create(['user_low' => $viewer->id, 'user_high' => $requester->id, 'requested_by' => $requester->id]);
    Friendship::query()->create(['user_low' => $other->id, 'user_high' => $stranger->id, 'requested_by' => $other->id]);

    $this->actingAs($viewer)->get('/friends')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/FeedPage')->where('collectionCount', 3)->missing('incomingRequests')
        ->where('auth.user.profileUrl', '/homeviewer'));
    $this->get('/friends/manage')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/FriendsPage')->has('people.data', 1)->where('people.data.0.id', $requester->id)
        ->has('connections', 1)->where('connections.0.requested_by', $requester->id)->where('connections.0.accepted_at', null));
});

it('publishes the about page and privacy policy without requiring sign-in', function (string $document) {
    app()->detectEnvironment(fn () => 'production');

    $response = $this->get(route($document))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('social/'.ucfirst($document).'Page'));
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');

    $this->actingAs(socialUser('documentreader'))->get(route($document))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('social/'.ucfirst($document).'Page'));
})->with(['about', 'privacy']);

it('previews unapproved terms locally without publishing them', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->get(route('terms'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('social/TermsPage'));

    app()->detectEnvironment(fn () => 'production');
    $this->get(route('terms'))->assertNotFound();
});

it('offers a safe way back from missing pages without revealing private collections', function () {
    config(['app.debug' => false]);
    $record = socialRecord(socialUser('private_error_owner'), 'Never disclose this album');

    foreach (['/missing/page', '/shelves/999999999'] as $path) {
        $this->get($path)->assertNotFound()
            ->assertSee('This one skipped.')
            ->assertSee('Back to the club')
            ->assertSee('href="/collections"', false)
            ->assertDontSee('Never disclose this album')
            ->assertDontSee('private_error_owner');
    }

    $this->get("/shelves/{$record->public_id}")->assertRedirect('/login')
        ->assertDontSee('Never disclose this album')->assertDontSee('private_error_owner');

    $this->getJson('/missing/page')->assertNotFound()->assertJsonStructure(['message'])
        ->assertDontSee('<html', false);
});

it('keeps error status codes and hides internal messages on branded error pages', function (int $status) {
    config(['app.debug' => false]);
    Route::get('/test-playback-error', fn () => abort($status, 'Do not expose internal details'));

    $response = $this->get('/test-playback-error')->assertStatus($status)
        ->assertSee('Plaat Club')
        ->assertDontSee('Do not expose internal details');

    if ($status === 503) {
        $response->assertSee('Changing the record.')->assertSee('Your collection is safe.')
            ->assertSee('Kraftwerk — Computer World')->assertSee('/images/records/11959599.jpg')
            ->assertDontSee('Back to the club')->assertDontSee('Browse public collections');

        return;
    }

    $response->assertSee("{$status} / Playback interrupted")->assertSee('Back to the club');
})->with([401, 403, 419, 429, 500, 503]);
