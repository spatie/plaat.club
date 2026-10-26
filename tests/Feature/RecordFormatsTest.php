<?php

use App\Actions\Collections\StoreCollectionRecordAction;
use App\Actions\Discogs\CompleteCollectionSyncAction;
use App\Actions\Discogs\ImportDiscogsRecordAction;
use App\Actions\Discogs\RefreshRecordFormatsAction;
use App\Jobs\SyncUserCollection;
use App\Models\User;
use App\Support\DiscogsRecordMapper;
use App\Support\RecordCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

it('classifies related physical formats while preserving their original labels', function (string $name, array $expected) {
    $mapper = app(DiscogsRecordMapper::class);
    $metadata = $mapper->formatMetadata(['formats' => [['name' => $name, 'qty' => '2', 'descriptions' => ['Album'], 'text' => 'Limited']]]);

    expect($metadata)->toBe(['format' => "2 × {$name}", 'edition' => 'Album, Limited', 'mediaFormats' => $expected])
        ->and($mapper->mediaFormats("2x{$name}"))->toBe($expected);
})->with([
    'CD' => ['CD', ['CD']],
    'recordable CD' => ['CDr', ['CD']],
    'hyphenated CD-R' => ['CD-R', ['CD']],
    'rewritable CD' => ['CD-RW', ['CD']],
    'super audio CD' => ['SACD', ['CD']],
    'CD video' => ['CDV', ['CD']],
    'vinyl' => ['Vinyl', ['Vinyl']],
    'acetate' => ['Acetate', ['Vinyl']],
    'flexi' => ['Flexi-disc', ['Vinyl']],
    'lathe cut' => ['Lathe Cut', ['Vinyl']],
    'shellac' => ['Shellac', ['Vinyl']],
    'cassette' => ['Cassette', ['Cassette']],
    'digital compact cassette' => ['DCC', ['Cassette']],
    'digital audio tape' => ['DAT', ['Cassette']],
    'microcassette' => ['Microcassette', ['Cassette']],
    'NT cassette' => ['NT Cassette', ['Cassette']],
    'DC international' => ['DC-International', ['Cassette']],
    'elcaset' => ['Elcaset', ['Cassette']],
    'DVD' => ['DVD', []],
    'recordable DVD' => ['DVDr', []],
    'blu-ray' => ['Blu-ray', []],
    'file' => ['File', []],
    'box set' => ['Box Set', []],
    '8-track cartridge' => ['8-Track Cartridge', []],
]);

it('corrects stored media families without changing other metadata or copy activity', function () {
    $record = socialRecord(socialUser('correctformats'));
    app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload,
        'format' => '2 × CDr, Vinyl, Cassette', 'mediaFormats' => ['Vinyl', 'Cassette'],
    ], 'shared_note' => 'My edition', 'private_note' => 'Gift']);
    $copyBefore = DB::table('collection_records')->first();
    $releaseBefore = DB::table('releases')->first();

    $migration = require database_path('migrations/2026_10_26_120000_correct_release_media_formats.php');
    $migration->up();
    $migration->up();

    expect($record->fresh()->formats())->toBe(['CD', 'Vinyl', 'Cassette'])
        ->and(DB::table('collection_records')->first())->toEqual($copyBefore)
        ->and(DB::table('releases')->first()->updated_at)->toBe($releaseBefore->updated_at)
        ->and($record->fresh()->payload)->toEqual([...$record->payload, 'mediaFormats' => ['CD', 'Vinyl', 'Cassette']]);
});

it('imports mixed bundles and unknown media without losing descriptions', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
    config(['services.discogs.consumer_key' => 'test', 'services.discogs.consumer_secret' => 'secret']);
    $user = socialUser('formats', 'everyone');
    $user->update(['discogs_username' => 'formats', 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $mixed = discogsCollectionEntry(101, 201);
    $mixed['basic_information']['master_id'] = 0;
    $mixed['basic_information']['formats'] = [
        ['name' => 'Vinyl', 'qty' => '2', 'descriptions' => ['LP', 'Reissue'], 'text' => 'Blue'],
        ['name' => 'CD', 'qty' => '1'], ['name' => 'Cassette', 'qty' => '1'],
    ];
    $unknown = [...$mixed, 'instance_id' => 102];
    $unknown['basic_information']['id'] = 202;
    $unknown['basic_information']['formats'] = [['name' => 'DVD', 'descriptions' => ['LP sized sleeve']]];
    Http::fake([
        'api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 2], 'releases' => [$mixed, $unknown]]),
        'api.discogs.com/releases/*' => Http::response(['tracklist' => []]),
    ]);
    (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    $record = $user->records()->where('instance_id', 101)->firstOrFail();
    expect($record->formats())->toBe(['Vinyl', 'CD', 'Cassette'])
        ->and($record->payload['format'])->toBe('2 × Vinyl, CD, Cassette')
        ->and($record->payload['edition'])->toBe('LP, Reissue, Blue');
    expect($user->records()->count())->toBe(2)
        ->and($user->records()->where('instance_id', 102)->firstOrFail()->formats())->toBe([]);
});

it('backfills legacy descriptions without changing dates or activity and recognizes snapshots stored later', function () {
    $user = socialUser('legacyformats');
    (require database_path('migrations/0001_01_01_000001_create_cache_table.php'))->up();
    Schema::table('collection_records', function (Blueprint $table) {
        $table->dropForeign(['discogs_release_id']);
        $table->json('payload');
    });
    Schema::dropIfExists('release_embeddings');
    foreach (['artist_release', 'artists', 'releases'] as $table) {
        Schema::drop($table);
    }
    foreach (['5 LP', 'CD, Cassette', 'DVD', ''] as $index => $format) {
        $payload = [...app(RecordCollection::class)->snapshot()[0], 'format' => $format];
        unset($payload['mediaFormats']);
        DB::table('collection_records')->insert([
            'user_id' => $user->id, 'instance_id' => 100 + $index, 'discogs_release_id' => 200 + $index,
            'public_id' => (string) Str::uuid(),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'is_activity' => false,
            'added_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-02 12:00:00',
        ]);
    }
    $before = DB::table('collection_records')->get(['added_at', 'updated_at', 'is_activity'])->toJson();
    $migration = require database_path('migrations/2026_10_18_120000_backfill_record_formats.php');
    $migration->up();
    $migration = require database_path('migrations/2026_10_23_120000_share_releases_and_artists.php');
    $migration->up();
    $migration = require database_path('migrations/2026_10_23_130000_promote_release_metadata_columns.php');
    $migration->up();
    expect(DB::table('collection_records')->get(['added_at', 'updated_at', 'is_activity'])->toJson())->toBe($before)
        ->and($user->records()->where('instance_id', 100)->firstOrFail()->formats())->toBe(['Vinyl'])
        ->and($user->records()->where('instance_id', 101)->firstOrFail()->formats())->toBe(['CD', 'Cassette'])
        ->and($user->records()->where('instance_id', 102)->firstOrFail()->formats())->toBe([]);
    $late = socialRecord($user, instance: 200);
    app(StoreCollectionRecordAction::class)->execute($late, ['payload' => [...$late->payload, 'format' => '5 LP']]);
    expect($late->fresh()->formats())->toBe(['Vinyl']);
});

it('refreshes known copies from the listing without fetching releases or changing notes dates and activity', function (bool $activity) {
    Http::preventStrayRequests();
    Sleep::fake();
    Sleep::syncWithCarbon();
    config(['services.discogs.consumer_key' => 'test', 'services.discogs.consumer_secret' => 'secret']);
    $user = socialUser('knownformats');
    $user->update(['discogs_username' => 'knownformats', 'discogs_token' => 'access', 'discogs_secret' => 'secret', 'collection_imported_at' => now()]);
    $record = socialRecord($user, activity: $activity);
    $record->update(['shared_note' => 'My edition', 'private_note' => 'Gift for a friend']);
    $entry = discogsCollectionEntry($record->instance_id, $record->discogs_release_id);
    $entry['basic_information']['formats'] = [['name' => 'CD', 'descriptions' => ['Remastered']]];
    Http::fake(['api.discogs.com/users/*' => Http::response(['pagination' => ['pages' => 1, 'items' => 1], 'releases' => [$entry]])]);
    $job = new SyncUserCollection($user->id);
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    $job->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    $fresh = $record->fresh();
    expect($fresh->formats())->toBe(['CD'])->and($fresh->payload['edition'])->toBe('Remastered')
        ->and($fresh->payload['tracks'])->toBe($record->payload['tracks'])
        ->and($fresh->added_at->equalTo($record->added_at))->toBeTrue()
        ->and($fresh->is_activity)->toBe($activity)
        ->and($fresh->shared_note)->toBe('My edition')->and($fresh->private_note)->toBe('Gift for a friend')
        ->and($fresh->toArray())->not->toHaveKey('private_note');
    Http::assertSentCount(2);
})->with([true, false]);

it('shares all media together without a format filter and preserves canonical formats for packaging', function () {
    $user = socialUser('shelfformats', 'everyone');
    foreach (['Vinyl album' => ['Vinyl'], 'CD and tape bundle' => ['CD', 'Cassette'], 'Unknown medium' => []] as $title => $formats) {
        $record = socialRecord($user, $title, instance: $user->records()->count() + 1);
        app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload, 'mediaFormats' => $formats]]);
    }
    foreach (['', '?format=CD', '?format=DVD'] as $query) {
        $response = $this->get('/people/shelfformats'.$query)->assertOk()
            ->assertSee('Vinyl album')->assertSee('CD and tape bundle')->assertSee('Unknown medium')
            ->assertDontSee('name="format"', false);
        $summaries = collect($response->inertiaProps('records.data'))->keyBy('title');
        expect($summaries['Vinyl album']['mediaFormats'])->toBe(['Vinyl'])
            ->and($summaries['CD and tape bundle']['mediaFormats'])->toBe(['CD', 'Cassette'])
            ->and($summaries['Unknown medium']['mediaFormats'])->toBe([]);
    }
});

it('keeps useful legacy descriptions when listing metadata is incomplete', function () {
    $record = socialRecord(socialUser('incompleteformats'));
    $payload = $record->payload;
    expect(app(RefreshRecordFormatsAction::class)->execute($record, ['formats' => [['descriptions' => ['LP']]]]))->toBeFalse()
        ->and($record->fresh()->payload)->toEqual($payload);
});

it('seeds all three formats without overwriting edited demo records', function () {
    Http::preventStrayRequests();
    app()->detectEnvironment(fn () => 'local');
    $owner = socialUser('robin', 'friends');
    $legacy = app(DiscogsRecordMapper::class)->record([
        'instance_id' => 900000, 'date_added' => '2026-09-01T12:00:00+00:00',
        'basic_information' => ['id' => 900000, 'title' => 'After the Rain', 'artists' => [['name' => 'The Paper Satellites']], 'year' => 2026, 'labels' => [['name' => 'Synthetic demo release']], 'cover_image' => '', 'formats' => [['name' => 'Vinyl', 'qty' => '1']]],
    ], ['year' => 2026, 'tracklist' => [['title' => 'A Small Beginning', 'position' => 'A1', 'duration' => '4:12']]], []);
    $legacy['cover'] = '/images/demo-sleeve-1.svg';
    $original = app(StoreCollectionRecordAction::class)->execute($owner->records()->make(), ['instance_id' => 900000, 'discogs_release_id' => 900000, 'payload' => $legacy, 'added_at' => '2026-09-01 12:00:00', 'is_activity' => false, 'private_note' => 'My gift']);
    $edited = app(StoreCollectionRecordAction::class)->execute($owner->records()->make(), ['instance_id' => 900001, 'discogs_release_id' => 900001, 'payload' => [...$legacy, 'id' => 900001, 'title' => 'Tester changed this'], 'added_at' => '2026-09-01 12:00:00', 'is_activity' => false]);
    $this->artisan('social:seed-demo')->assertSuccessful();
    $robin = User::query()->where('handle', 'robin')->firstOrFail();
    expect($original->fresh()->id)->toBe($original->id)
        ->and($original->fresh()->discogs_release_id)->not->toBe(900000)
        ->and($original->fresh()->private_note)->toBe('My gift')
        ->and($original->fresh()->added_at->equalTo($original->added_at))->toBeTrue()
        ->and($original->fresh()->is_activity)->toBeFalse()
        ->and($edited->fresh()->payload)->toEqual($edited->payload)
        ->and($edited->fresh()->payload['title'])->toBe('Tester changed this');
    foreach (['Vinyl', 'CD', 'Cassette'] as $format) {
        expect($robin->records()->get()->contains(fn ($record) => in_array($format, $record->formats(), true)))->toBeTrue();
    }
    $record = $robin->records()->firstOrFail();
    app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload, 'edition' => 'My own edit'], 'private_note' => 'Keep this']);
    $this->artisan('social:seed-demo')->assertSuccessful();
    expect($record->fresh()->payload['edition'])->toBe('My own edit')->and($record->fresh()->private_note)->toBe('Keep this')
        ->and($robin->records()->count())->toBe(90);
});
