<?php

use App\Models\CollectionRecord;
use App\Models\User;
use App\Support\RecordCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

it('verifies existing emails silently without overwriting verification dates or verifying future signups', function () {
    Notification::fake();
    $existing = socialUser('existing_unverified');
    $existing->update(['email_verified_at' => null]);
    $verified = socialUser('already_verified');
    $verified->update(['email_verified_at' => now()->subYear()]);
    $originalDate = $verified->fresh()->email_verified_at;
    $this->travelTo(now()->startOfSecond());

    $migration = require database_path('migrations/2026_10_22_210000_verify_existing_email_addresses.php');
    $migration->up();

    expect($existing->fresh()->email_verified_at->equalTo(now()))->toBeTrue()
        ->and($verified->fresh()->email_verified_at->equalTo($originalDate))->toBeTrue();
    $new = User::query()->create(['email' => 'future@example.test', 'password' => 'a-long-demo-password']);
    expect($new->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertNothingSent();
});

it('migrates the existing collection losslessly and silently to Freek’s reserved account', function () {
    $connection = DB::getDefaultConnection();
    config(['database.connections.social_migration' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    DB::setDefaultConnection('social_migration');

    try {
        $this->artisan('migrate', ['--database' => 'social_migration'])->assertSuccessful();
        (require database_path('migrations/0001_01_01_000001_create_cache_table.php'))->up();
        foreach (['record_list_entries', 'record_lists', 'collection_browse_entries', 'social_notifications', 'comments', 'appreciations', 'friendships', 'collection_records', 'artist_release', 'artists', 'releases', 'invitations', 'users', 'password_reset_tokens'] as $table) {
            Schema::drop($table);
        }
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
        Schema::create('collection_records', function (Blueprint $table) {
            $table->unsignedBigInteger('instance_id')->primary();
            $table->unsignedBigInteger('discogs_release_id');
            $table->string('added_at');
            $table->json('payload');
            $table->timestamp('updated_at')->nullable();
        });
        $records = array_slice(app(RecordCollection::class)->snapshot(), 0, 3);
        foreach ($records as $record) {
            DB::table('collection_records')->insert(['instance_id' => $record['instanceId'], 'discogs_release_id' => $record['id'], 'added_at' => '2026-10-01 12:00:00', 'payload' => json_encode($record, JSON_THROW_ON_ERROR), 'updated_at' => '2026-10-02 12:00:00']);
        }
        $migration = require database_path('migrations/2026_10_18_100000_create_social_collections.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_23_100000_index_collection_browsing.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_23_120000_share_releases_and_artists.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_23_130000_promote_release_metadata_columns.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_23_140000_add_public_ids.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_22_100000_create_record_lists.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_23_190000_add_public_ids_to_record_lists.php');
        $migration->up();
        $migration = require database_path('migrations/2026_10_27_100000_make_list_entries_release_based.php');
        $migration->up();
        $freek = User::freek();
        expect($freek->email)->toBe('freek@spatie.be')->and($freek->visibility)->toBe('everyone')->and($freek->collection_imported_at)->not->toBeNull();
        expect(CollectionRecord::legacy()->count())->toBe(3)->and(CollectionRecord::query()->where('is_activity', true)->count())->toBe(0);
        foreach ($records as $record) {
            $copy = CollectionRecord::legacy()->where('instance_id', $record['instanceId'])->firstOrFail();
            expect($copy->payload)->toEqual([...$record, 'addedAt' => '2026-10-01T12:00:00+00:00'])->and($copy->added_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 12:00:00');
        }
        $this->assertDatabaseCount('social_notifications', 0);
        $publicId = CollectionRecord::legacy()->where('instance_id', $records[0]['instanceId'])->value('public_id');
        $this->getJson('/api/records/'.$publicId)->assertOk()->assertJsonPath('title', $records[0]['title']);
        $this->getJson('/api/records/'.$records[0]['instanceId'])->assertNotFound();

        $this->get('/freek?mode=artist')->assertOk()->assertInertia(fn (Assert $page) => $page->where('records.total', 3));
        foreach ($records as $record) {
            expect(CollectionRecord::legacy()->where('instance_id', $record['instanceId'])->firstOrFail()->payload)->toEqual([...$record, 'addedAt' => '2026-10-01T12:00:00+00:00']);
        }
    } finally {
        DB::purge('social_migration');
        DB::setDefaultConnection($connection);
    }
});
