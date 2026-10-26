<?php

use App\Models\CollectionRecord;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('lets an owner edit and clear notes on only the selected copy without activity or notifications', function () {
    Notification::fake();
    $owner = socialUser('notes_owner');
    $friend = socialUser('notes_friend');
    socialFriends($owner, $friend);
    $record = socialRecord($owner, activity: false);
    $copy = socialRecord($owner, instance: 124);
    $otherCopy = socialRecord($friend);
    $before = $record->only(['payload', 'added_at', 'is_activity']);

    $this->actingAs($owner)->patch("/shelves/{$record->public_id}/notes", [
        'shared_note' => "First line\nSecond line", 'private_note' => 'Paid €20',
        'is_activity' => true, 'user_id' => $friend->id, 'payload' => [],
    ])->assertRedirect($record->url());
    expect($record->fresh()->only(array_keys($before)))->toEqual($before)
        ->and($record->fresh()->shared_note)->toBe("First line\nSecond line")
        ->and($record->fresh()->private_note)->toBe('Paid €20')
        ->and($copy->fresh()->shared_note)->toBeNull()
        ->and($otherCopy->fresh()->private_note)->toBeNull();
    $this->get("/shelves/{$record->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('record.canEditNotes', true)->where('record.privateNote', 'Paid €20')
        ->where('record.sharedNote', "First line\nSecond line"));
    expect(CollectionRecord::friendActivity($friend)->whereKey($record->id)->exists())->toBeFalse();
    $overlay = "/notes_owner?record={$record->public_id}";
    $this->actingAs($owner)->from($overlay)->patch("/shelves/{$record->public_id}/notes", ['shared_note' => '', 'private_note' => ''])
        ->assertSessionHasNoErrors()->assertRedirect($overlay);
    $this->get($overlay)->assertInertia(fn (Assert $page) => $page
        ->component('social/ProfilePage')->where('recordOverlay.record.id', $record->id)
        ->where('recordOverlay.record.sharedNote', null)->where('recordOverlay.record.privateNote', null));
    expect($record->fresh()->shared_note)->toBeNull()->and($record->fresh()->private_note)->toBeNull();
    $this->assertDatabaseCount('collection_records', 3);
    $this->assertDatabaseCount('social_notifications', 0);
    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('appreciations', 0);
    Notification::assertNothingSent();
});

it('requires an authenticated verified owner even for another copy of the same release', function () {
    $owner = socialUser('notes_owner', 'everyone');
    $other = socialUser('notes_other', 'everyone');
    $record = socialRecord($owner);
    socialRecord($other);
    $notes = ['shared_note' => 'Not yours', 'private_note' => 'Not yours'];
    $this->patch("/shelves/{$record->public_id}/notes", $notes)->assertRedirect('/login');
    $this->actingAs($other)->patch("/shelves/{$record->public_id}/notes", $notes)->assertForbidden();
    $owner->update(['visibility' => 'only_me']);
    $this->patch("/shelves/{$record->public_id}/notes", $notes)->assertForbidden();
    $owner->update(['email_verified_at' => null]);
    $this->flushSession();
    $this->actingAs($owner)->patch("/shelves/{$record->public_id}/notes", $notes)->assertRedirect('/verify-email');
    expect($record->fresh()->shared_note)->toBeNull()->and($record->fresh()->private_note)->toBeNull();
});

it('validates both note fields and accepts the 5000 character boundary', function () {
    $owner = socialUser('notes_owner');
    $record = socialRecord($owner);
    $this->actingAs($owner)->patch("/shelves/{$record->public_id}/notes", ['shared_note' => str_repeat('x', 5001), 'private_note' => str_repeat('y', 5001)])->assertSessionHasErrors(['shared_note', 'private_note']);
    $this->patch("/shelves/{$record->public_id}/notes", ['shared_note' => [], 'private_note' => []])->assertSessionHasErrors(['shared_note', 'private_note']);
    $this->patch("/shelves/{$record->public_id}/notes", [])->assertSessionHasErrors(['shared_note', 'private_note']);
    $this->patch("/shelves/{$record->public_id}/notes", ['shared_note' => str_repeat('x', 5000), 'private_note' => str_repeat('y', 5000)])->assertSessionHasNoErrors();
    expect(strlen($record->fresh()->shared_note))->toBe(5000)->and(strlen($record->fresh()->private_note))->toBe(5000);
});

it('inherits current collection visibility and never renders private notes or owner fields to viewers', function (string $visibility, string $viewer, bool $visible) {
    $owner = socialUser('notes_owner', $visibility);
    $record = socialRecord($owner);
    $record->update(['shared_note' => "<script>alert('shared')</script>\nNext line", 'private_note' => 'secret-owner-note']);
    if ($viewer !== 'guest') {
        $person = socialUser('notes_viewer');
        if ($viewer === 'friend') {
            socialFriends($owner, $person);
        }
        $this->actingAs($person);
    }
    $response = $this->get("/shelves/{$record->public_id}");
    if ($visible) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('record.sharedNote', $record->shared_note)->where('record.canEditNotes', false)
            ->missing('record.privateNote'))->assertDontSee('<script>alert', false);
    } else {
        $response->assertRedirect($viewer === 'guest' ? '/login' : '/notes_owner')->assertDontSee('Next line');
    }
    $response->assertDontSee('secret-owner-note')->assertDontSee('name="shared_note"', false)->assertDontSee('name="private_note"', false);
    $owner->update(['visibility' => 'only_me']);
    $this->get("/shelves/{$record->public_id}")->assertRedirect($viewer === 'guest' ? '/login' : '/notes_owner')
        ->assertDontSee('Next line')->assertDontSee('secret-owner-note');
})->with([
    ['everyone', 'guest', true], ['everyone', 'stranger', true], ['everyone', 'friend', true],
    ['friends', 'guest', false], ['friends', 'stranger', false], ['friends', 'friend', true],
    ['only_me', 'guest', false], ['only_me', 'stranger', false], ['only_me', 'friend', false],
]);

it('escapes owner textarea content and omits empty shared note panels for visitors', function () {
    $owner = socialUser('notes_owner', 'everyone');
    $record = socialRecord($owner);
    $this->get("/shelves/{$record->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('record.sharedNote', null)->missing('record.privateNote'));
    $attack = '</textarea><script>alert("note")</script>';
    $this->actingAs($owner)->patch("/shelves/{$record->public_id}/notes", ['shared_note' => $attack, 'private_note' => $attack])->assertSessionHasNoErrors();
    $this->get("/shelves/{$record->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('record.sharedNote', $attack)->where('record.privateNote', $attack))->assertDontSee($attack, false);
});

it('excludes private notes from serialization and both notes from the legacy public API', function () {
    $owner = User::freek();
    $record = socialRecord($owner);
    $record->update(['shared_note' => 'unique-shared-note', 'private_note' => 'unique-private-note']);
    expect($record->fresh()->toArray())->not->toHaveKey('private_note');
    expect($record->fresh()->toJson())->not->toContain('unique-private-note');
    $this->getJson("/api/records/{$record->public_id}")->assertOk()->assertDontSee('unique-private-note')->assertDontSee('unique-shared-note');
    $this->followingRedirects()->get("/record/{$record->public_id}")->assertOk()->assertDontSee('unique-private-note')->assertSee('unique-shared-note');
    expect(CollectionRecord::query()->findOrFail($record->id)->private_note)->toBe('unique-private-note');
});
