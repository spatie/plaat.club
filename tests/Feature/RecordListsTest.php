<?php

use App\Actions\Collections\StoreCollectionRecordAction;
use App\Ai\Tools\ReadRecordLists;
use App\Ai\Tools\WriteRecordList;
use App\Models\CollectionRecord;
use App\Models\RecordList;
use App\Models\RecordListEntry;
use App\Models\Release;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request as ToolRequest;

it('backfills stable unique list public ids without changing existing lists or their entries', function () {
    $connection = DB::getDefaultConnection();
    config(['database.connections.list_migration' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    DB::setDefaultConnection('list_migration');

    try {
        $this->artisan('migrate', ['--database' => 'list_migration'])->assertSuccessful();
        $owner = socialUser('list_owner');
        $copy = socialRecord($owner);
        Schema::table('record_lists', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
        DB::table('record_lists')->insert([
            ['id' => 3, 'user_id' => $owner->id, 'name' => 'Test', 'description' => 'Keep this.', 'visibility' => 'friends', 'created_at' => '2026-10-22 12:00:00', 'updated_at' => '2026-10-22 13:00:00'],
            ['id' => 4, 'user_id' => $owner->id, 'name' => 'Another list', 'description' => null, 'visibility' => 'only_me', 'created_at' => '2026-10-22 12:00:00', 'updated_at' => '2026-10-22 13:00:00'],
        ]);
        DB::table('record_list_entries')->insert([
            ['record_list_id' => 3, 'collection_record_id' => $copy->id, 'position' => 7],
            ['record_list_id' => 3, 'collection_record_id' => null, 'position' => 8],
        ]);
        $lists = DB::table('record_lists')->orderBy('id')->get()->map(fn ($list) => (array) $list)->all();
        $entries = DB::table('record_list_entries')->orderBy('id')->get()->all();
        $migration = require database_path('migrations/2026_10_23_190000_add_public_ids_to_record_lists.php');

        $migration->up();

        $publicIds = RecordList::query()->orderBy('id')->pluck('public_id')->all();
        expect($publicIds)->toHaveCount(2)->and(array_unique($publicIds))->toHaveCount(2);
        foreach ($publicIds as $publicId) {
            expect(Str::isUuid($publicId))->toBeTrue();
        }
        expect(DB::table('record_lists')->orderBy('id')->get()->map(fn ($list) => collect((array) $list)->except('public_id')->all())->all())->toBe($lists)
            ->and(DB::table('record_list_entries')->orderBy('id')->get()->all())->toEqual($entries);

        $migration->up();

        expect(RecordList::query()->orderBy('id')->pluck('public_id')->all())->toBe($publicIds)
            ->and(DB::table('record_list_entries')->orderBy('id')->get()->all())->toEqual($entries);
    } finally {
        DB::purge('list_migration');
        DB::setDefaultConnection($connection);
    }
});

it('looks up pasted release URLs exactly without numeric text search or storing release metadata', function () {
    Http::preventStrayRequests();
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Exact URL']);
    Http::fake([
        'api.discogs.com/database/search*' => Http::response(['results' => [['id' => 9999, 'title' => 'An unrelated numeric catalogue result']]]),
        'api.discogs.com/releases/880005' => Http::response([
            'id' => 880005, 'title' => 'The exact release', 'year' => 2026, 'country' => 'Belgium',
            'artists' => [['name' => 'Exact Artist']], 'formats' => [['name' => 'Vinyl']],
            'labels' => [['name' => 'Exact label']], 'tracklist' => [],
        ]),
    ]);
    $this->actingAs($owner)->get("/lists/{$list->public_id}/edit?source=discogs&q=".urlencode('https://www.discogs.com/release/880005-The-Exact-Release'))
        ->assertInertia(fn (Assert $page) => $page->has('results.data', 1)->where('searchError', null)
            ->where('results.data.0.releaseId', 880005)->where('results.data.0.displayTitle', 'The exact release')
            ->where('results.data.0.country', 'Belgium')->where('results.data.0.label', 'Exact label'));
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'database/search'));
    expect(Release::query()->whereKey(880005)->exists())->toBeFalse()->and($list->entries()->count())->toBe(0);
    $known = socialRecord(socialUser('private_collector'), 'Known release', releaseId: 880006);
    $this->get("/lists/{$list->public_id}/edit?source=discogs&q=".urlencode('https://www.discogs.com/release/880006-Known'))
        ->assertInertia(fn (Assert $page) => $page->has('results.data', 1)->where('results.data.0.releaseId', $known->discogs_release_id));
    Http::assertSentCount(1);
});

it('rejects hostile and unsupported pasted URLs without requesting any host', function (string $query) {
    Http::preventStrayRequests();
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Safe search']);
    $this->actingAs($owner)->get("/lists/{$list->public_id}/edit?source=discogs&q=".urlencode($query))
        ->assertInertia(fn (Assert $page) => $page->has('results.data', 0)
            ->where('searchError', 'Paste a Discogs release URL, not an artist, master or another website.'));
    Http::assertNothingSent();
})->with([
    'https://www.discogs.com.attacker.test/release/123', 'https://www.discogs.com@attacker.test/release/123',
    'http://127.0.0.1/release/123', 'https://www.discogs.com/master/123',
    'https://www.discogs.com/release/not-a-number', 'ftp://www.discogs.com/release/123',
]);

it('reads unified lists and preserves release only entries during copy based AI changes and stale confirmations', function () {
    $owner = socialUser('list_owner');
    $mine = socialRecord($owner, 'Owned copy');
    $foreign = socialRecord(socialUser('list_collector'), 'Release only');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Mixed list']);
    $saved = $list->entries()->create(['release_id' => $foreign->discogs_release_id, 'position' => 7, 'private_note' => 'Do not expose']);
    $read = json_decode((new ReadRecordLists($owner))->handle(new ToolRequest(['list' => $list->public_id])), true, flags: JSON_THROW_ON_ERROR);
    expect($read['lists'][0]['count'])->toBe(1)->and($read['lists'][0]['records'][0])->toMatchArray([
        'entryPublicId' => $saved->public_id, 'releaseId' => $foreign->discogs_release_id, 'title' => 'Release only',
    ])->and($read['lists'][0]['records'][0])->not->toHaveKey('recordId')
        ->and(json_encode($read))->not->toContain('Do not expose');
    $tool = new WriteRecordList($owner);
    $arguments = ['action' => 'update', 'list' => $list->public_id, 'name' => null, 'description' => null,
        'visibility' => null, 'recordMode' => 'add', 'recordIds' => [$mine->id]];
    $tool->handle(new ToolRequest($arguments));
    expect($list->entries()->pluck('release_id')->all())->toBe([$foreign->discogs_release_id, $mine->discogs_release_id])
        ->and($saved->fresh()->private_note)->toBe('Do not expose');
    $tool->handle(new ToolRequest([...$arguments, 'recordMode' => 'remove']));
    expect($list->entries()->sole()->public_id)->toBe($saved->public_id);
    $prepared = $tool->prepare(new ToolRequest($arguments));
    $list->entries()->create(['release_id' => $foreign->discogs_release_id, 'position' => 8]);
    expect(fn () => $tool->handle(new ToolRequest($prepared)))->toThrow(ValidationException::class);
    expect($list->entries()->count())->toBe(2)->and($list->records()->count())->toBe(0);
});

it('keeps list edits unchanged when trusted release imports fail safely', function (int $status) {
    Http::preventStrayRequests();
    Sleep::fake();
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Unchanged']);
    Http::fake(['api.discogs.com/releases/880005' => Http::response(['message' => 'Secret provider error'], $status)]);
    $this->actingAs($owner)->patch("/lists/{$list->public_id}", ['name' => 'Should not save', 'entries' => [['releaseId' => 880005]]])
        ->assertSessionHasErrors(['entries' => 'Discogs is unavailable or busy. Please try again shortly.']);
    expect($list->fresh()->name)->toBe('Unchanged')->and($list->entries()->count())->toBe(0)
        ->and(Release::query()->whereKey(880005)->exists())->toBeFalse()->and($owner->records()->count())->toBe(0);
})->with([401, 404, 429, 503]);

it('batches list metadata copy identity and ownership queries even for a thousand entries', function () {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Large list']);
    $list->records()->attach($copy, ['position' => 0]);
    $this->actingAs($owner);
    DB::enableQueryLog();
    $this->get("/lists/{$list->public_id}")->assertOk();
    $singleCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    $entries = [];
    foreach (range(1, 999) as $position) {
        $entries[] = ['record_list_id' => $list->id, 'public_id' => (string) Str::uuid(), 'release_id' => $copy->discogs_release_id, 'position' => $position];
    }
    DB::table('record_list_entries')->insert($entries);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get("/lists/{$list->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('records', 1000)->where('records.999.owned', true));
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($largeCount)->toBeLessThanOrEqual($singleCount + 2);
});

it('uses UUID list and copy routes while retaining numeric selection ids', function () {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'UUID list']);
    $list->records()->attach($copy, ['position' => 0]);

    $this->actingAs($owner)->get('/lists')->assertInertia(fn (Assert $page) => $page
        ->where('lists.0.id', $list->id)->where('lists.0.publicId', $list->public_id));
    $this->get("/lists/{$list->id}")->assertNotFound();
    $this->get("/lists/{$list->id}/edit")->assertNotFound();
    $this->patch("/lists/{$list->id}", ['name' => 'Wrong route'])->assertNotFound();
    $this->delete("/lists/{$list->id}")->assertNotFound();
    foreach (["/lists/{$list->id}/records/{$copy->public_id}", "/lists/{$list->public_id}/records/{$copy->id}"] as $url) {
        $this->get($url)->assertNotFound();
        $this->get($url.'/cover')->assertNotFound();
        $this->post($url)->assertNotFound();
        $this->delete($url)->assertNotFound();
    }
    expect($list->fresh()->name)->toBe('UUID list')->and($list->records()->count())->toBe(1);
});

it('previews the first four ordered covers of each owned list while counting all records', function () {
    $owner = socialUser('list_owner');
    $copies = collect(range(1, 6))->map(fn (int $index) => socialRecord($owner, "Record {$index}", instance: 100 + $index));
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Six records']);
    foreach ($copies->reverse()->values() as $position => $copy) {
        $list->records()->attach($copy, ['position' => $position]);
    }
    $small = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'One record', 'updated_at' => now()->subMinute()]);
    $small->records()->attach($copies->first(), ['position' => 0]);
    $empty = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Empty', 'updated_at' => now()->subMinutes(2)]);
    RecordList::query()->create(['user_id' => socialUser('other_owner')->id, 'name' => 'Not mine', 'visibility' => 'everyone']);

    $this->actingAs($owner)->get('/lists')->assertInertia(fn (Assert $page) => $page
        ->has('lists', 3)
        ->where('lists.0.publicId', $list->public_id)->where('lists.0.count', 6)
        ->where('lists.0.covers', $list->entries()->limit(4)->get()->map(fn (RecordListEntry $entry) => route('lists.entries.cover', [$list, $entry], false))->all())
        ->where('lists.1.publicId', $small->public_id)->where('lists.1.count', 1)
        ->where('lists.1.covers', [route('lists.entries.cover', [$small, $small->entries()->first()], false)])
        ->where('lists.2.publicId', $empty->public_id)->where('lists.2.count', 0)->has('lists.2.covers', 0));
});

it('preserves the audience when replacing inherited list visibility', function () {
    foreach (['only_me', 'friends', 'everyone'] as $visibility) {
        $owner = socialUser("owner_{$visibility}", $visibility);
        $inherited = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Inherited', 'visibility' => 'collection']);
        $private = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Private']);

        (require database_path('migrations/2026_10_23_100000_make_list_visibility_independent.php'))->up();

        expect($inherited->fresh()->visibility)->toBe($visibility)
            ->and($private->fresh()->visibility)->toBe('only_me');
    }
});

it('shows a placeholder for listed records whose artwork is not available yet', function () {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner, 'Awaiting artwork');
    app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'cover' => '/images/record-placeholder.svg']]);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'New imports', 'visibility' => 'everyone']);
    $list->records()->attach($copy, ['position' => 0]);

    $this->get('/collections?tab=lists')->assertInertia(fn (Assert $page) => $page
        ->where('lists.data.0.covers.0', route('lists.entries.cover', [$list, $list->entries()->first()], false)));
    $this->get(route('lists.cover', [$list, $copy], false))->assertRedirect('/images/record-placeholder.svg');
    $details = $this->getJson(route('lists.record', [$list, $copy], false))->assertOk();
    $this->get($details->json('cover'))->assertRedirect('/images/record-placeholder.svg');
});

it('creates private lists and saves only owned copies in the chosen order', function () {
    $owner = socialUser('list_owner');
    $other = socialUser('list_other');
    $first = socialRecord($owner, 'First pick', instance: 101);
    $second = socialRecord($owner, 'Second pick', instance: 102);
    $foreign = socialRecord($other, 'Not mine', instance: 101);

    $this->actingAs($owner)->post('/lists', ['name' => 'Sunday records', 'description' => 'Slow mornings.', 'user_id' => $other->id])->assertRedirect();
    $list = RecordList::query()->sole();
    expect($list->visibility)->toBe('only_me')->and($list->user_id)->toBe($owner->id);

    $this->patch("/lists/{$list->public_id}", ['recordIds' => [$second->id, $first->id]])
        ->assertRedirect('/lists')->assertSessionHas('status', 'List saved.');
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->component('social/ListPage')->where('list.id', $list->id)->where('list.publicId', $list->public_id)
        ->where('records.0.copyId', $second->id)->where('records.0.copyPublicId', $second->public_id)->where('records.1.copyId', $first->id));
    $this->patch("/lists/{$list->public_id}", ['name' => 'Do not save', 'visibility' => 'everyone', 'recordIds' => [$foreign->id]])->assertSessionHasErrors('recordIds.0');
    $this->patch("/lists/{$list->public_id}", ['recordIds' => [$first->id, $first->id]])->assertSessionHasErrors('recordIds.0');
    $this->patch("/lists/{$list->public_id}", ['recordIds' => ['wrongPosition' => $first->id]])->assertSessionHasErrors('recordIds');
    expect($list->fresh()->name)->toBe('Sunday records')->and($list->fresh()->visibility)->toBe('only_me');

    $this->patch("/lists/{$list->public_id}", ['recordIds' => [$first->id]])->assertSessionHasNoErrors();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page->has('records', 1)->where('records.0.copyId', $first->id));
    $this->patch("/lists/{$list->public_id}", ['recordIds' => []])->assertSessionHasNoErrors();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page->has('records', 0));
});

it('creates a list with the chosen visibility without changing collection privacy', function (string $visibility) {
    $owner = socialUser('list_owner');
    $other = socialUser('list_other');

    $this->actingAs($owner)->post('/lists', ['name' => 'Chosen audience', 'visibility' => $visibility, 'user_id' => $other->id])->assertSessionHasNoErrors();
    $list = RecordList::query()->sole();
    expect($list->visibility)->toBe($visibility)->and($list->user_id)->toBe($owner->id)
        ->and($owner->fresh()->visibility)->toBe('only_me');
    $this->patch("/lists/{$list->public_id}", ['visibility' => $visibility])
        ->assertSessionHasNoErrors()->assertRedirect("/lists/{$list->public_id}");

    $this->app['auth']->forgetGuards();
    $this->flushSession();
    if ($visibility === 'friends') {
        $this->get("/lists/{$list->public_id}")->assertRedirect('/login');

        return;
    }
    $visibility === 'everyone'
        ? $this->get("/lists/{$list->public_id}")->assertOk()
        : $this->get("/lists/{$list->public_id}")->assertNotFound();
})->with(['only_me', 'friends', 'everyone']);

it('rejects unsupported visibility when creating a list', function () {
    $this->actingAs(socialUser('list_owner'))->post('/lists', ['name' => 'Invalid audience', 'visibility' => 'collection'])->assertSessionHasErrors('visibility');
    $this->assertDatabaseCount('record_lists', 0);
});

it('creates a list from an owned release and returns to that release with the record selected', function () {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner, 'Pick from a release');
    $foreign = socialRecord(socialUser('list_other'), 'Not owned');

    $this->actingAs($owner)->from(route('profile', $owner->handle))->post('/lists', ['name' => 'New from release', 'visibility' => 'everyone', 'copyId' => $copy->id])->assertRedirect($copy->url(false));
    $list = RecordList::query()->sole();
    expect($list->records()->pluck('collection_records.id')->all())->toBe([$copy->id]);
    $this->get($copy->url(false))->assertInertia(fn (Assert $page) => $page
        ->where('recordOverlay.record.lists.0.id', $list->id)->where('recordOverlay.record.lists.0.publicId', $list->public_id)
        ->where('recordOverlay.record.lists.0.name', 'New from release')->where('recordOverlay.record.lists.0.containsRecord', true));
    $this->post('/lists', ['name' => 'Not allowed', 'copyId' => $foreign->id])->assertSessionHasErrors('copyId');
    $this->assertDatabaseCount('record_lists', 1);
});

it('appends a release to an owned list once without changing its existing order', function () {
    $owner = socialUser('list_owner');
    $first = socialRecord($owner, 'First');
    $copy = socialRecord($owner, 'Append me', instance: 100);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Existing list']);
    $list->records()->attach($first, ['position' => 7]);
    $other = socialUser('list_other');
    $foreignList = RecordList::query()->create(['user_id' => $other->id, 'name' => 'Foreign list']);
    $foreign = socialRecord($other, 'Not owned');

    $this->actingAs($owner)->from(route('profile', $owner->handle))->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertRedirect($copy->url(false));
    $this->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertSessionHasNoErrors();
    $this->post("/lists/{$list->public_id}/records/{$first->public_id}")->assertSessionHasNoErrors();
    expect($list->records()->pluck('collection_records.id')->all())->toBe([$first->id, $copy->id]);
    $this->delete("/lists/{$list->public_id}/records/{$first->public_id}")->assertRedirect($first->url(false));
    $this->delete("/lists/{$list->public_id}/records/{$first->public_id}")->assertSessionHasNoErrors();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->has('records', 1)->where('records.0.copyId', $copy->id));
    $this->post("/lists/{$list->public_id}/records/{$first->public_id}")->assertSessionHasNoErrors();
    expect($list->records()->pluck('collection_records.id')->all())->toBe([$copy->id, $first->id]);
    $this->post("/lists/{$foreignList->public_id}/records/{$copy->public_id}")->assertNotFound();
    $this->post("/lists/{$list->public_id}/records/{$foreign->public_id}")->assertNotFound();
    $this->delete("/lists/{$foreignList->public_id}/records/{$copy->public_id}")->assertNotFound();
    $this->delete("/lists/{$list->public_id}/records/{$foreign->public_id}")->assertNotFound();
    $this->actingAs($other)->get($first->url(false))->assertRedirect(route('profile', $owner->handle));
    $owner->update(['visibility' => 'everyone']);
    $this->get($first->url(false))->assertInertia(fn (Assert $page) => $page
        ->has('recordOverlay.record.lists', 1)->where('recordOverlay.record.lists.0.publicId', $foreignList->public_id)
        ->where('recordOverlay.record.lists.0.containsRecord', false));
    $owner->update(['email_verified_at' => null]);
    $this->actingAs($owner)->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertRedirect('/verify-email');
    $this->delete("/lists/{$list->public_id}/records/{$copy->public_id}")->assertRedirect('/verify-email');
});

it('keeps capacity errors on the release without changing a full list', function () {
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Nearly full']);
    $last = socialRecord($owner, 'Last release', instance: 1000);
    $extra = socialRecord($owner, 'Extra release', instance: 1001);
    $seed = socialRecord($owner, 'Repeated physical copies', instance: 1);
    $attributes = $seed->getAttributes();
    unset($attributes['id']);
    $copies = [];
    foreach (range(2, 999) as $instance) {
        $copies[] = [...$attributes, 'public_id' => (string) Str::uuid(), 'instance_id' => $instance];
    }
    CollectionRecord::query()->insert($copies);
    $list->records()->attach($owner->records()->where('instance_id', '<', 1000)->pluck('id')
        ->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])->all());

    $this->actingAs($owner)->from(route('profile', $owner->handle))
        ->post("/lists/{$list->public_id}/records/{$last->public_id}")->assertSessionHasNoErrors();
    $this->post("/lists/{$list->public_id}/records/{$extra->public_id}")
        ->assertRedirect($extra->url(false))->assertSessionHasErrors('record');
    expect($list->records()->count())->toBe(1000)
        ->and($list->records()->whereKey($extra->id)->exists())->toBeFalse();
    $this->post("/lists/{$list->public_id}/records/{$last->public_id}")->assertSessionHasNoErrors();
});

it('keeps standalone release pages open when adding to or creating a list', function () {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner, 'Standalone release');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Existing']);

    $this->actingAs($owner)->from(route('profile', $owner->handle))
        ->withHeader('X-Inertia-Partial-Component', 'social/RecordPage')
        ->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertRedirect(route('shelf.record', $copy));
    $this->post('/lists', ['name' => 'New', 'visibility' => 'everyone', 'copyId' => $copy->id])->assertRedirect(route('shelf.record', $copy));
    $this->get(route('shelf.record', $copy))->assertInertia(fn (Assert $page) => $page
        ->component('social/RecordPage')->has('record.lists', 2)
        ->where('record.lists.0.visibility', 'only_me')->where('record.lists.1.visibility', 'everyone')
        ->where('record.lists.0.containsRecord', true)->where('record.lists.1.containsRecord', true));
    $this->delete("/lists/{$list->public_id}/records/{$copy->public_id}")->assertRedirect(route('shelf.record', $copy));
    $this->get(route('shelf.record', $copy))->assertInertia(fn (Assert $page) => $page
        ->component('social/RecordPage')->where('record.lists.0.containsRecord', false)
        ->where('record.lists.1.containsRecord', true));
});

it('requires verification and never allows another collector to manage lists', function () {
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Hidden list']);
    $other = socialUser('list_other');

    $this->get('/lists')->assertRedirect('/login');
    $this->post('/lists', ['name' => 'Nope'])->assertRedirect('/login');
    $this->actingAs($other)->get('/lists')->assertInertia(fn (Assert $page) => $page->has('lists', 0));
    $this->get("/lists/{$list->public_id}/edit")->assertNotFound();
    $this->patch("/lists/{$list->public_id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->delete("/lists/{$list->public_id}")->assertNotFound();
    $other->update(['email_verified_at' => null]);
    $this->post('/lists', ['name' => 'Nope'])->assertRedirect('/verify-email');
    expect($list->fresh()->name)->toBe('Hidden list');
});

it('enforces list access separately from collection access for pages details and covers', function (string $listVisibility, string $collectionVisibility, string $audience, bool $allowed) {
    $owner = socialUser('list_owner', $collectionVisibility);
    $copy = socialRecord($owner, 'Listed record');
    app(StoreCollectionRecordAction::class)->execute($copy, ['payload' => [...$copy->payload, 'cover' => '/images/demo-sleeve-1.jpg']]);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Selected records', 'visibility' => $listVisibility]);
    $list->records()->attach($copy, ['position' => 0]);
    if ($audience !== 'guest') {
        $viewer = $audience === 'owner' ? $owner : socialUser('list_viewer');
        if (in_array($audience, ['friend', 'pending'])) {
            $friendship = socialFriends($owner, $viewer);
            if ($audience === 'pending') {
                $friendship->update(['accepted_at' => null]);
            }
        }
        $this->actingAs($viewer);
    }

    foreach (["/lists/{$list->public_id}", "/lists/{$list->public_id}/records/{$copy->public_id}", "/lists/{$list->public_id}/records/{$copy->public_id}/cover"] as $url) {
        $response = $this->get($url);
        if ($url === "/lists/{$list->public_id}" && $listVisibility === 'friends' && $audience === 'guest') {
            $response->assertRedirect('/login');
        } else {
            $allowed ? $response->assertOk() : $response->assertNotFound();
        }
        expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
            ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue();
    }
})->with([
    ['only_me', 'everyone', 'guest', false], ['only_me', 'everyone', 'friend', false], ['only_me', 'only_me', 'owner', true],
    ['friends', 'only_me', 'friend', true], ['friends', 'friends', 'friend', true], ['friends', 'friends', 'pending', false],
    ['friends', 'friends', 'guest', false], ['friends', 'everyone', 'guest', false], ['friends', 'friends', 'stranger', false],
    ['friends', 'everyone', 'stranger', false], ['friends', 'only_me', 'owner', true],
    ['everyone', 'only_me', 'guest', true], ['everyone', 'friends', 'stranger', true],
]);

it('publishes only selected record metadata without opening the private collection or its social data', function () {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner, 'Public selection');
    app(StoreCollectionRecordAction::class)->execute($copy, ['shared_note' => 'A shared secret', 'private_note' => 'A private secret', 'payload' => [...$copy->payload, 'cover' => '/images/demo-sleeve-1.jpg', 'secretPayload' => 'Never serialize me']]);
    $hidden = socialRecord($owner, 'Still private', instance: 456);
    $copy->comments()->create(['user_id' => $owner->id, 'body' => 'A private conversation']);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Just these']);
    $list->records()->attach($copy, ['position' => 0]);

    $this->actingAs($owner)->patch("/lists/{$list->public_id}", ['visibility' => 'everyone'])->assertSessionHasNoErrors();
    expect($owner->fresh()->visibility)->toBe('only_me');
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->get("/lists/{$list->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('records', 1)->where('records.0.copyId', $copy->id)->where('list.canEdit', false)
        ->where('socialPreview.title', 'Just these · List_owner'))
        ->assertDontSee(['A shared secret', 'A private secret', 'Still private', 'A private conversation', 'Never serialize me']);
    $details = $this->getJson("/lists/{$list->public_id}/records/{$copy->public_id}")->assertOk()->assertJsonPath('displayTitle', 'Public selection')
        ->assertJsonMissingPath('shared_note')->assertJsonMissingPath('private_note')->assertJsonMissingPath('secretPayload')->assertJsonMissingPath('user');
    expect($details->json('tracks.0.title'))->toBe($copy->payload['tracks'][0]['title']);
    $this->get("/lists/{$list->public_id}/records/{$hidden->public_id}")->assertNotFound();
    $this->get("/lists/{$list->public_id}/records/{$hidden->public_id}/cover")->assertNotFound();
    $this->get($hidden->url(false))->assertRedirect('/login');
    $this->get("/shelves/{$copy->public_id}/cover")->assertNotFound();

    $this->get("/lists/{$list->public_id}/records/{$copy->public_id}/cover")->assertOk();
    $this->actingAs($owner)->patch("/lists/{$list->public_id}", ['visibility' => 'only_me'])->assertSessionHasNoErrors();
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->get("/lists/{$list->public_id}")->assertNotFound();
    $this->get("/lists/{$list->public_id}/records/{$copy->public_id}")->assertNotFound();
    $this->get("/lists/{$list->public_id}/records/{$copy->public_id}/cover")->assertNotFound();
});

it('finds owned records across picker pages and searches without returning other collections', function () {
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Picking']);
    foreach (range(1, 38) as $number) {
        socialRecord($owner, "Album {$number}", instance: $number);
    }
    $target = socialRecord($owner, 'Café selection', instance: 100);
    socialRecord(socialUser('list_other'), 'Café selection', instance: 100);
    $list->records()->attach($target, ['position' => 0]);
    $this->actingAs($owner)->get("/lists/{$list->public_id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('social/ListEditPage')->has('results.data', 36)->has('records', 1)->where('records.0.copyId', $target->id));
    $this->get("/lists/{$list->public_id}/edit?recordsPage=2")->assertInertia(fn (Assert $page) => $page
        ->has('results.data', 3)->has('records', 1)->where('records.0.copyId', $target->id));
    $this->get("/lists/{$list->public_id}/edit?q=cafe%20selection")->assertInertia(fn (Assert $page) => $page
        ->has('results.data', 1)->where('results.data.0.copyId', $target->id)->where('results.data.0.copyPublicId', $target->public_id));
    $this->get("/lists/{$list->public_id}/edit?q=nonexistent")->assertInertia(fn (Assert $page) => $page->has('results.data', 0)->has('records', 1));
});

it('retains release entries when their physical copies are removed', function () {
    $owner = socialUser('list_owner');
    $removed = socialRecord($owner, 'Removed from Discogs');
    $kept = socialRecord($owner, 'Still owned', instance: 456);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'My selection', 'visibility' => 'everyone']);
    $list->records()->attach([$removed->id => ['position' => 0], $kept->id => ['position' => 1]]);
    $owner->records()->whereKey($removed->id)->delete();

    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->has('records', 2)->where('records.0.releaseId', $removed->discogs_release_id)
        ->missing('records.0.copyId')->missing('records.0.owned')->where('list.removedCount', 0));
    $this->actingAs($owner)->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->has('records', 2)->where('records.0.owned', false)->where('records.1.copyId', $kept->id)->where('list.removedCount', 0));
    $this->patch("/lists/{$list->public_id}", ['recordIds' => [$kept->id]])->assertSessionHasNoErrors();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page->where('list.removedCount', 0));
    $this->delete("/lists/{$list->public_id}")->assertRedirect('/lists');
    expect($kept->fresh())->not->toBeNull();
    $this->assertDatabaseCount('record_list_entries', 0);
});

it('discovers only accessible lists on a private collectors profile', function (string $audience, int $count) {
    $owner = socialUser('list_owner');
    $copy = socialRecord($owner, 'Never reveal the private shelf');
    foreach (['everyone', 'friends', 'only_me'] as $visibility) {
        $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => $visibility, 'visibility' => $visibility]);
        $list->records()->attach($copy, ['position' => 0]);
    }
    RecordList::query()->create(['user_id' => socialUser('other_owner')->id, 'name' => 'Not this collector', 'visibility' => 'everyone']);
    if ($audience !== 'guest') {
        $viewer = $audience === 'owner' ? $owner : socialUser('list_viewer');
        if (in_array($audience, ['friend', 'pending'])) {
            $friendship = socialFriends($owner, $viewer);
            if ($audience === 'pending') {
                $friendship->update(['accepted_at' => null]);
            }
        }
        $this->actingAs($viewer);
    }

    $this->get('/list_owner/lists')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/ProfileListsPage')->where('person.handle', 'list_owner')
        ->where('listCount', $count)->has('lists.data', $count)->missing('records')
        ->where('lists.data.0.visibility', $audience === 'owner' ? 'only_me' : ($audience === 'friend' ? 'friends' : 'everyone')));
    $profile = $this->get('/list_owner')->assertOk();
    $profile->assertInertia(fn (Assert $page) => $page->where('listCount', $count));
    if ($audience !== 'owner') {
        $profile->assertInertia(fn (Assert $page) => $page->where('visible', false)->where('records', null));
        foreach ([$copy->url(false), "/list_owner?record={$copy->public_id}"] as $url) {
            $this->get($url)->assertRedirect($audience === 'guest' ? '/login' : '/list_owner');
        }
        $this->get("/list_owner/lists?record={$copy->public_id}")->assertNotFound();
    }
})->with([['guest', 1], ['stranger', 1], ['pending', 1], ['friend', 2], ['owner', 3]]);

it('paginates public lists independently of collection visibility and removes hidden lists immediately', function () {
    $owner = socialUser('private_collector');
    $viewer = socialUser('list_viewer');
    socialFriends($owner, $viewer);
    $copy = socialRecord($owner);
    foreach (range(1, 13) as $index) {
        $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => "Selection {$index}", 'visibility' => 'everyone']);
        $list->records()->attach($copy, ['position' => 0]);
    }
    foreach (['friends', 'only_me'] as $visibility) {
        RecordList::query()->create(['user_id' => $owner->id, 'name' => "Hidden {$visibility}", 'visibility' => $visibility]);
    }

    $this->get('/private_collector/lists')->assertInertia(fn (Assert $page) => $page
        ->where('listCount', 13)->has('lists.data', 12)->where('lists.next_page_url', url('/private_collector/lists?page=2')));
    $this->get('/private_collector/lists?page=2')->assertInertia(fn (Assert $page) => $page
        ->where('listCount', 13)->has('lists.data', 1)->where('lists.data.0.name', 'Selection 1'));
    $this->actingAs($viewer)->get('/collections?tab=lists')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'lists')->has('lists.data', 12)->missing('collectors')
        ->where('lists.data.0.name', 'Selection 13')->where('lists.data.0.owner.handle', 'private_collector')
        ->where('lists.data.0.count', 1)->has('lists.data.0.covers', 1)
        ->where('lists.next_page_url', url('/collections?tab=lists&page=2')));
    $this->get('/collections?tab=lists&page=2')->assertInertia(fn (Assert $page) => $page
        ->has('lists.data', 1)->where('lists.data.0.name', 'Selection 1')->where('lists.next_page_url', null));
    $list->update(['visibility' => 'only_me']);
    $this->get('/collections?tab=lists')->assertInertia(fn (Assert $page) => $page
        ->has('lists.data', 12)->where('lists.data.0.name', 'Selection 12')->where('lists.next_page_url', null));
    $this->get('/collections')->assertInertia(fn (Assert $page) => $page->has('collectors.data', 0)->missing('lists'));
    $this->get('/list_owner_missing/lists')->assertNotFound();
});

it('does not disclose hidden lists in an empty profile overview', function () {
    $owner = socialUser('private_collector');
    RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Secret selection', 'visibility' => 'only_me']);

    $this->get('/private_collector/lists')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('listCount', 0)->has('lists.data', 0))->assertDontSee('Secret selection');
    $this->get('/private_collector')->assertRedirect('/login');
    $this->get('/collections?tab=lists')->assertInertia(fn (Assert $page) => $page->has('lists.data', 0));
});

it('saves visible foreign releases independently of source copies and exposes only the viewers own lists', function () {
    Http::preventStrayRequests();
    $owner = socialUser('list_owner');
    $collector = socialUser('list_collector', 'everyone');
    $copy = socialRecord($collector, 'Foreign release');
    $copy->update(['private_note' => 'source-private-note', 'shared_note' => 'source-shared-note']);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Wanted', 'visibility' => 'everyone']);
    $empty = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Empty']);
    $this->actingAs($owner)->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertSessionHasNoErrors();
    $entry = $list->entries()->sole();
    expect($entry->release_id)->toBe($copy->discogs_release_id)->and($entry->collection_record_id)->toBeNull()
        ->and($owner->records()->count())->toBe(0);
    $this->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertSessionHasNoErrors();
    expect($list->entries()->count())->toBe(1);
    $this->get(route('shelf.record', $copy))->assertInertia(fn (Assert $page) => $page
        ->where('record.lists.0.publicId', $empty->public_id)->where('record.lists.0.containsRecord', false)
        ->where('record.lists.1.publicId', $list->public_id)->where('record.lists.1.containsRecord', true));
    $this->post('/lists', ['name' => 'From foreign release', 'copyId' => $copy->id])->assertRedirect($copy->url(false));
    expect(RecordList::query()->latest('id')->first()->entries()->sole()->collection_record_id)->toBeNull();
    $collector->update(['visibility' => 'only_me']);
    $this->post("/lists/{$list->public_id}/records/{$copy->public_id}")->assertNotFound();
    $this->post('/lists', ['name' => 'Cannot access', 'copyId' => $copy->id])->assertSessionHasErrors('copyId');
    $copy->delete();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->has('records', 1)->where('records.0.entryPublicId', $entry->public_id)->where('records.0.owned', false));
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->missing('records.0.owned')->missing('records.0.otherPressingOwned')->missing('records.0.privateNote')
        ->missing('records.0.copyId'))->assertDontSee(['source-private-note', 'source-shared-note']);
    $this->getJson(route('lists.entries.show', [$list, $entry]))->assertOk()->assertJsonPath('displayTitle', 'Foreign release');
    $this->get(route('lists.entries.cover', [$list, $entry]))->assertOk();
    Http::assertNothingSent();
});

it('preserves existing entry UUIDs physical duplicates private notes and exact order when saving releases', function () {
    $owner = socialUser('list_owner');
    $first = socialRecord($owner, 'Two physical copies', instance: 1);
    $second = socialRecord($owner, 'Two physical copies', instance: 2);
    $other = socialRecord(socialUser('list_collector'), 'Release only');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Preserved']);
    $list->records()->attach([$first->id => ['position' => 7], $second->id => ['position' => 8]]);
    [$entryOne, $entryTwo] = $list->entries()->get()->all();
    $entryOne->update(['private_note' => 'Keep this note']);
    $this->actingAs($owner)->patch("/lists/{$list->public_id}", ['entries' => [
        ['entryPublicId' => $entryTwo->public_id, 'releaseId' => $second->discogs_release_id],
        ['releaseId' => $other->discogs_release_id, 'copyId' => $other->id],
        ['entryPublicId' => $entryOne->public_id, 'releaseId' => $first->discogs_release_id],
    ]])->assertSessionHasNoErrors()->assertRedirect('/lists');
    $entries = $list->entries()->get();
    expect($entries->pluck('public_id')->all())->toBe([$entryTwo->public_id, $entries[1]->public_id, $entryOne->public_id])
        ->and($entries->pluck('position')->all())->toBe([0, 1, 2])
        ->and($entries->pluck('collection_record_id')->all())->toBe([$second->id, null, $first->id])
        ->and($entries[2]->private_note)->toBe('Keep this note');
    $foreignList = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Another']);
    foreach ([
        ['entryPublicId' => $entries[0]->public_id, 'releaseId' => $other->discogs_release_id],
        ['entryPublicId' => (string) Str::uuid(), 'releaseId' => $first->discogs_release_id],
    ] as $invalid) {
        $this->patch("/lists/{$list->public_id}", ['name' => 'Do not change', 'entries' => [$invalid]])->assertSessionHasErrors('entries.0.entryPublicId');
    }
    $this->patch("/lists/{$foreignList->public_id}", ['entries' => [['entryPublicId' => $entryOne->public_id, 'releaseId' => $first->discogs_release_id]]])
        ->assertSessionHasErrors('entries.0.entryPublicId');
    expect($list->fresh()->name)->toBe('Preserved')->and($list->entries()->count())->toBe(3);
    $this->patch("/lists/{$list->public_id}", ['entries' => []])->assertSessionHasNoErrors();
    expect($list->entries()->count())->toBe(0)->and($owner->records()->count())->toBe(2);
});

it('derives ownership and other pressing status from the list owners current collection without exposing it to visitors', function () {
    $owner = socialUser('list_owner');
    $owned = socialRecord($owner, 'Owned pressing');
    $wanted = socialRecord(socialUser('list_collector'), 'Different pressing');
    $owned->release->update(['discogs_master_id' => 987654]);
    $wanted->release->update(['discogs_master_id' => 987654]);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Any ownership state', 'visibility' => 'everyone']);
    $entry = $list->entries()->create(['release_id' => $wanted->discogs_release_id, 'position' => 0]);
    $this->actingAs($owner)->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('records.0.owned', false)->where('records.0.otherPressingOwned', true));
    $acquired = socialRecord($owner, 'Different pressing', instance: 456, releaseId: $wanted->discogs_release_id);
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('records.0.owned', true)->where('records.0.otherPressingOwned', false));
    $acquired->delete();
    $owned->delete();
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('records.0.owned', false)->where('records.0.otherPressingOwned', false));
    expect($entry->fresh())->not->toBeNull();
    $this->actingAs(socialUser('list_visitor'))->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page
        ->missing('records.0.owned')->missing('records.0.otherPressingOwned')->missing('records.0.privateNote'));
});

it('authorizes entry details covers and bounded private notes before accessing cached artwork', function () {
    $owner = socialUser('list_owner');
    $friend = socialUser('list_friend');
    $friendship = socialFriends($owner, $friend);
    $copy = socialRecord($owner);
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Friends only', 'visibility' => 'friends']);
    $entry = $list->entries()->create(['release_id' => $copy->discogs_release_id, 'private_note' => 'entry-secret', 'position' => 0]);
    $another = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Another', 'visibility' => 'everyone']);
    $url = route('lists.entries.show', [$list, $entry], false);
    $cover = route('lists.entries.cover', [$list, $entry], false);
    $this->actingAs($friend)->get($cover)->assertOk();
    $this->getJson($url)->assertOk()->assertDontSee('entry-secret');
    $this->getJson(route('lists.entries.show', [$another, $entry]))->assertNotFound();
    $this->get(route('lists.entries.cover', [$another, $entry]))->assertNotFound();
    $this->patch($url, ['privateNote' => 'intrusion'])->assertNotFound();
    $friendship->delete();
    $this->get($cover)->assertNotFound();
    $this->getJson($url)->assertNotFound();
    $this->flushSession();
    $this->actingAs($owner)->patch($url, ['privateNote' => str_repeat('x', 2001)])->assertRedirect()->assertSessionHasErrors('privateNote');
    $this->patch($url, ['privateNote' => 'Saved privately'])->assertSessionHasNoErrors();
    expect($entry->fresh()->private_note)->toBe('Saved privately');
    $this->get("/lists/{$list->public_id}")->assertInertia(fn (Assert $page) => $page->where('records.0.privateNote', 'Saved privately'));
    $this->patch($url, ['privateNote' => null])->assertSessionHasNoErrors();
    expect($entry->fresh()->private_note)->toBeNull();
});

it('searches Discogs releases with cached paginated results without importing releases until save', function () {
    Http::preventStrayRequests();
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Search']);
    $url = "/lists/{$list->public_id}/edit?source=discogs";
    foreach (['', 'a', 'ab'] as $query) {
        $this->actingAs($owner)->get($url.'&q='.$query)->assertInertia(fn (Assert $page) => $page
            ->where('source', 'discogs')->where('searchError', null)->has('results.data', 0));
    }
    Http::assertNothingSent();
    Http::fake([
        'api.discogs.com/database/search*' => Http::response([
            'pagination' => ['page' => 1, 'pages' => 2],
            'results' => [
                ['id' => 880005, 'type' => 'release', 'title' => 'Trusted Artist (11) - Search album', 'year' => 2026, 'country' => 'Belgium', 'label' => ['Trusted label', 'Pressing plant', 'Recording studio'], 'catno' => 'TR-123', 'format' => ['CD', 'Album'], 'cover_image' => '', 'thumb' => 'https://i.discogs.com/thumb.jpg'],
                ['id' => 880006, 'type' => 'release', 'title' => 'Another Artist - No artwork', 'cover_image' => '', 'thumb' => ''],
            ],
        ]),
        'api.discogs.com/releases/880005' => Http::response([
            'id' => 880005, 'title' => 'Trusted album', 'year' => 2026, 'country' => 'Belgium',
            'artists' => [['id' => 880050, 'name' => 'Trusted Artist']],
            'formats' => [['name' => 'Vinyl', 'qty' => '1']], 'labels' => [],
            'images' => [['uri' => 'https://i.discogs.com/full.jpg']],
            'tracklist' => [['type_' => 'track', 'position' => 'A1', 'title' => 'Trusted track', 'duration' => '4:12']],
        ]),
    ]);
    foreach (range(1, 2) as $attempt) {
        $this->get($url.'&q=Search%20album')->assertInertia(fn (Assert $page) => $page
            ->where('source', 'discogs')->where('query', 'Search album')->where('searchError', null)
            ->where('results.data.0.releaseId', 880005)->where('results.data.0.artist', 'Trusted Artist')
            ->where('results.data.0.country', 'Belgium')->where('results.data.0.label', 'Trusted label')
            ->where('results.data.0.cover', 'https://i.discogs.com/thumb.jpg')
            ->where('results.data.0.catalogNumber', 'TR-123')->where('results.data.0.mediaFormats', ['CD'])
            ->where('results.data.0.discogsUrl', 'https://www.discogs.com/release/880005')
            ->where('results.data.1.cover', "/lists/{$list->public_id}/releases/880006/cover")
            ->where('results.data.0.displayTitle', 'Search album')->where('results.data.0.owned', false)
            ->where('results.next_page_url', url($url.'&q=Search%20album&recordsPage=2')));
    }
    Http::assertSentCount(1);
    expect(Release::query()->whereKey(880005)->exists())->toBeFalse();
    $this->patch("/lists/{$list->public_id}", ['entries' => [['releaseId' => 880005, 'title' => 'Untrusted client title']]])
        ->assertSessionHasNoErrors();
    expect($list->entries()->sole()->release->title)->toBe('Trusted album')
        ->and($list->entries()->sole()->release->cover)->toBe('https://i.discogs.com/full.jpg')
        ->and($list->entries()->sole()->release->recordData()['country'])->toBe('Belgium')
        ->and($owner->records()->count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'database/search') && $request['type'] === 'release' && $request['per_page'] === 36);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/artists/'));
});

it('puts matching owned editions first even when absent from the Discogs page without repeating copies or editions', function () {
    Http::preventStrayRequests();
    $owner = socialUser('search_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Search']);
    $missing = socialRecord($owner, 'Rubber Soul', instance: 1, releaseId: 880011);
    socialRecord($owner, 'Rubber Soul', instance: 2, releaseId: 880012);
    socialRecord($owner, 'Rubber Soul', instance: 3, releaseId: 880011);
    socialRecord($owner, 'Unrelated album', instance: 4, releaseId: 880013);
    socialRecord(socialUser('other_collector'), 'Rubber Soul', releaseId: 880014);
    Http::fake(['api.discogs.com/database/search*' => Http::response([
        'pagination' => ['pages' => 100],
        'results' => [
            ['id' => 880010, 'title' => 'The Beatles - Rubber Soul'],
            ['id' => 880012, 'title' => 'The Beatles - Rubber Soul'],
            ['id' => 880015, 'title' => 'The Beatles - Rubber Soul'],
        ],
    ])]);
    $url = "/lists/{$list->public_id}/edit?source=discogs&q=Rubber%20Soul";
    $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('results.data', fn ($records) => $records->pluck('releaseId')->all() === [880011, 880012, 880010, 880015])
        ->where('results.data.0.owned', true)->where('results.data.1.owned', true)
        ->where('results.data.2.owned', false)->where('results.data.3.owned', false)
        ->where('results.data.0.displayTitle', 'Rubber Soul')
        ->where('results.next_page_url', url($url.'&recordsPage=2')));
    $this->get($url.'&recordsPage=2')->assertInertia(fn (Assert $page) => $page
        ->where('results.data', fn ($records) => $records->pluck('releaseId')->all() === [880010, 880015])
        ->where('results.prev_page_url', url($url.'&recordsPage=1')));
    $owner->records()->where('discogs_release_id', $missing->discogs_release_id)->delete();
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('results.data', fn ($records) => $records->pluck('releaseId')->all() === [880012, 880010, 880015]));
    Http::assertSentCount(2);
    $this->assertDatabaseCount('record_list_entries', 0);
});

it('lazily loads missing search artwork from trusted release details without importing a collection record', function () {
    Http::preventStrayRequests();
    $owner = socialUser('artwork_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Artwork', 'visibility' => 'everyone']);
    $url = "/lists/{$list->public_id}/releases/880009/cover";
    $this->get($url)->assertRedirect('/login');
    $this->actingAs(socialUser('artwork_visitor'))->get($url)->assertNotFound();
    Http::assertNothingSent();
    $this->flushSession();

    $bytes = file_get_contents(public_path('images/demo-sleeve-1.jpg'));
    Http::fake([
        'api.discogs.com/releases/880009' => Http::response([
            'id' => 880009, 'title' => 'CD edition', 'artists' => [['name' => 'Artist']],
            'formats' => [['name' => 'CD', 'qty' => '1']],
            'images' => [['uri' => 'https://i.discogs.com/list-search-cover.jpg']],
        ]),
        'i.discogs.com/list-search-cover.jpg' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);
    foreach (range(1, 2) as $attempt) {
        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent($bytes);
    }
    Http::assertSentCount(2);
    expect(Release::query()->whereKey(880009)->exists())->toBeFalse()
        ->and($owner->records()->count())->toBe(0)->and($list->entries()->count())->toBe(0);
    $this->flushSession();
    $this->actingAs(socialUser('cached_artwork_visitor'))->get($url)->assertNotFound();
    Http::assertSentCount(2);
    Http::fake(['api.discogs.com/releases/880010' => Http::response([], 429)]);
    $this->flushSession();
    $this->actingAs($owner)->get("/lists/{$list->public_id}/releases/880010/cover")->assertRedirect('/images/record-placeholder.svg');
});

it('reports safe Discogs search failures', function (int $status) {
    Http::preventStrayRequests();
    $owner = socialUser('list_owner');
    $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Search']);
    Http::fake(['api.discogs.com/database/search*' => Http::response(['message' => 'secret provider diagnostics'], $status)]);
    $this->actingAs($owner)->get("/lists/{$list->public_id}/edit?source=discogs&q=An%20Album")
        ->assertInertia(fn (Assert $page) => $page->where('source', 'discogs')->has('results.data', 0)
            ->where('searchError', 'Discogs search is unavailable or busy. Please try again shortly.'))
        ->assertDontSee('secret provider diagnostics');
    Http::assertSent(fn ($request) => $request['q'] === 'An Album' && $request['type'] === 'release');
    $this->assertDatabaseCount('record_list_entries', 0);
})->with([401, 429, 503]);

it('migrates existing ordered duplicate copies and tombstones into release-based lists', function () {
    $connection = DB::getDefaultConnection();
    config(['database.connections.release_list_migration' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    DB::setDefaultConnection('release_list_migration');

    try {
        $this->artisan('migrate', ['--database' => 'release_list_migration'])->assertSuccessful();
        expect(Schema::hasTable('wishlist_items'))->toBeFalse()->and(Schema::hasColumn('users', 'wishlist_visibility'))->toBeFalse();
        $owner = socialUser('list_owner');
        $first = socialRecord($owner, 'Duplicate release', instance: 1);
        $second = socialRecord($owner, 'Duplicate release', instance: 2);
        $list = RecordList::query()->create(['user_id' => $owner->id, 'name' => 'Existing list', 'visibility' => 'everyone']);
        Schema::drop('record_list_entries');
        Schema::create('record_list_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_record_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->unique(['record_list_id', 'collection_record_id']);
        });
        DB::table('record_list_entries')->insert([
            ['id' => 5, 'record_list_id' => $list->id, 'collection_record_id' => $second->id, 'position' => 7],
            ['id' => 6, 'record_list_id' => $list->id, 'collection_record_id' => null, 'position' => 8],
            ['id' => 7, 'record_list_id' => $list->id, 'collection_record_id' => $first->id, 'position' => 9],
        ]);
        $before = $list->only(['public_id', 'name', 'visibility']);
        (require database_path('migrations/2026_10_27_100000_make_list_entries_release_based.php'))->up();

        expect($list->fresh()->only(['public_id', 'name', 'visibility']))->toBe($before);
        $entries = $list->entries()->get();
        expect($entries->pluck('id')->all())->toBe([5, 6, 7])
            ->and($entries->pluck('position')->all())->toBe([7, 8, 9])
            ->and($entries->pluck('collection_record_id')->all())->toBe([$second->id, null, $first->id])
            ->and($entries->pluck('release_id')->all())->toBe([$first->discogs_release_id, null, $first->discogs_release_id])
            ->and($entries->pluck('public_id')->unique()->count())->toBe(3);
        foreach ($entries as $entry) {
            expect(Str::isUuid($entry->public_id))->toBeTrue();
        }
        $second->delete();
        expect($entries[0]->fresh()->release_id)->toBe($first->discogs_release_id)
            ->and($entries[0]->fresh()->collection_record_id)->toBeNull();
    } finally {
        DB::purge('release_list_migration');
        DB::setDefaultConnection($connection);
    }
});
