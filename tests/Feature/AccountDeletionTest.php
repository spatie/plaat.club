<?php

use App\Actions\Discogs\CompleteCollectionSyncAction;
use App\Actions\Discogs\ImportDiscogsRecordAction;
use App\Jobs\SyncUserCollection;
use App\Models\Comment;
use App\Models\SocialNotification;
use App\Models\User;
use App\Support\RecordCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Converse\Models\Thread;

it('requires a verified session and exact username confirmation before deleting an account', function () {
    $this->get('/settings/delete-account')->assertRedirect('/login');
    $this->delete('/settings/account', ['confirmation' => 'collector'])->assertRedirect('/login');
    $user = socialUser('collector');
    $user->update(['email_verified_at' => null]);
    $this->actingAs($user)->delete('/settings/account', ['confirmation' => 'collector'])->assertRedirect('/verify-email');
    $user->update(['email_verified_at' => now()]);
    $this->get('/settings/delete-account')->assertOk()->assertInertia(fn (Assert $page) => $page->component('social/DeleteAccountPage'));

    foreach (['', 'someone_else', 'Collector'] as $confirmation) {
        $this->from('/settings/delete-account')->delete('/settings/account', ['confirmation' => $confirmation])
            ->assertRedirect('/settings/delete-account')->assertSessionHasErrors('confirmation');
        expect($user->fresh())->not->toBeNull();
    }
});

it('deletes only the confirmed signed-in account and its social data without changing Discogs or other collections', function (bool $google) {
    Http::preventStrayRequests();
    $user = socialUser('departing');
    $user->update(['google_id' => $google ? 'google-account' : null, 'discogs_token' => 'access', 'discogs_secret' => 'secret']);
    $other = socialUser('remaining', 'everyone');
    $mine = socialRecord($user);
    $theirs = socialRecord($other);
    socialFriends($user, $other);
    Comment::query()->create(['user_id' => $user->id, 'collection_record_id' => $theirs->id, 'body' => 'Remove my comment']);
    $keptComment = Comment::query()->create(['user_id' => $other->id, 'collection_record_id' => $theirs->id, 'body' => 'Keep this comment']);
    DB::table('appreciations')->insert(['user_id' => $user->id, 'collection_record_id' => $theirs->id]);
    SocialNotification::send($other, $user, 'comment', $theirs);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'old-reset-token', 'created_at' => now()]);
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $conversation = Thread::query()->create(['bot' => 'collection', 'connection' => 'web', 'external_id' => 'departing-conversation', 'channel' => $user->getMorphClass().':'.$user->id]);
    $otherConversation = Thread::query()->create(['bot' => 'collection', 'connection' => 'web', 'external_id' => 'remaining-conversation', 'channel' => $other->getMorphClass().':'.$other->id]);
    config(['session.driver' => 'database']);

    $this->actingAs($user)->delete('/settings/account', ['confirmation' => 'departing', 'user_id' => $other->id])
        ->assertRedirect('/')->assertSessionHas('status');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('collection_records', ['id' => $mine->id]);
    $this->assertDatabaseHas('collection_records', ['id' => $theirs->id, 'user_id' => $other->id]);
    $this->assertDatabaseHas('comments', ['id' => $keptComment->id]);
    $this->assertDatabaseMissing('comments', ['user_id' => $user->id]);
    $this->assertDatabaseCount('friendships', 0);
    $this->assertDatabaseCount('appreciations', 0);
    $this->assertDatabaseCount('social_notifications', 0);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('converse_threads', ['id' => $conversation->id]);
    $this->assertDatabaseHas('converse_threads', ['id' => $otherConversation->id]);
    $this->get('/shelves/'.$mine->public_id)->assertNotFound();
    $this->get('/shelves/'.$theirs->public_id)->assertOk();

    (new SyncUserCollection($user->id))->handle(app(ImportDiscogsRecordAction::class), app(CompleteCollectionSyncAction::class));
    expect(User::query()->find($user->id))->toBeNull();
    Http::assertNothingSent();
})->with([false, true]);

it('does not delete an account while its Discogs sync is writing records', function () {
    $user = socialUser('syncing');
    $record = socialRecord($user);
    $lock = Cache::lock('discogs-sync-'.$user->id, 7500);
    $lock->get();
    try {
        $this->actingAs($user)->from('/settings/delete-account')->delete('/settings/account', ['confirmation' => $user->handle])
            ->assertRedirect('/settings/delete-account')->assertSessionHasErrors('confirmation');
        expect($record->fresh())->not->toBeNull()->and($user->fresh())->not->toBeNull();
    } finally {
        $lock->release();
    }
    $this->delete('/settings/account', ['confirmation' => $user->handle])->assertRedirect('/');
    expect($user->fresh())->toBeNull();
});

it('revokes warmed legacy collection pages and stored previews when the legacy owner deletes their account', function () {
    $owner = User::freek();
    $owner->update(['email_verified_at' => now()]);
    $record = socialRecord($owner, 'A formerly public record');
    $collection = app(RecordCollection::class);
    expect($collection->findSummary($record->instance_id))->not->toBeNull();
    Storage::disk('public')->put('share/collection-old.jpg', 'old preview');
    Storage::disk('public')->put('share/records/old.jpg', 'old record preview');
    config(['services.laravel_cloud.purge_token' => 'test-purge-token', 'services.laravel_cloud.environment_id' => 'test-environment']);
    Http::fake(['cloud.laravel.com/api/environments/test-environment/purge-edge-cache' => Http::response([], 200)]);

    $this->actingAs($owner)->delete('/settings/account', ['confirmation' => $owner->handle])->assertRedirect('/');

    $this->get('/record/'.$record->public_id)->assertNotFound();
    $this->get('/collection')->assertNotFound();
    Storage::disk('public')->assertMissing(['share/collection-old.jpg', 'share/records/old.jpg']);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://cloud.laravel.com/api/environments/test-environment/purge-edge-cache');
});
