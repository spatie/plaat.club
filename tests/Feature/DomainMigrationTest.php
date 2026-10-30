<?php

use App\Models\Friendship;
use App\Notifications\FriendRequest;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects old and www domains without losing the path query or HTTP method', function (string $host, string $method) {
    $path = '/freek?search=Massive%20Attack&record=123&sort=oldest';

    $this->call($method, 'https://'.$host.$path)
        ->assertStatus(308)
        ->assertRedirect('https://plaatclub.com'.$path);
})->with(['plaat.club', 'www.plaat.club', 'www.plaatclub.com'])->with(['GET', 'HEAD', 'POST']);

it('serves the new domain and leaves local development hosts alone', function (string $origin) {
    $this->get($origin.'/login')->assertOk();
})->with(['https://plaatclub.com', 'http://localhost']);

it('keeps old signed friend links usable only at the new canonical host without allowing tampering', function () {
    Notification::fake();
    $sender = socialUser('sender');
    $recipient = socialUser('recipient');
    $friendship = Friendship::query()->create([
        'user_low' => min($sender->id, $recipient->id),
        'user_high' => max($sender->id, $recipient->id),
        'requested_by' => $sender->id,
    ]);
    URL::forceRootUrl('https://plaat.club');
    URL::forceScheme('https');
    $oldUrl = (new FriendRequest($friendship->id))->toMail($recipient)->viewData['links']['accept'];
    URL::forceRootUrl(null);
    URL::forceScheme(null);
    $newUrl = str_replace('https://plaat.club', 'https://plaatclub.com', $oldUrl);

    $this->get($oldUrl)->assertRedirect($newUrl);
    $this->get($newUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->where('sender', $sender->handle));
    expect($friendship->fresh()->accepted_at)->toBeNull();

    foreach ([str_replace('/accept?', '/decline?', $newUrl), $newUrl.'&extra=1', str_replace('plaatclub.com', 'untrusted.example', $newUrl)] as $tampered) {
        $this->get($tampered)->assertForbidden();
        $this->post($tampered)->assertForbidden();
    }

    $this->post($newUrl)->assertRedirect($newUrl);
    expect($friendship->fresh()->accepted_at)->not->toBeNull();

    $this->travel(7)->days();
    $this->travel(1)->seconds();
    $this->get($newUrl)->assertForbidden();
    $this->post($newUrl)->assertForbidden();
});

it('verifies an existing email link after redirecting to the new domain', function () {
    $user = socialUser('unverified');
    $user->update(['email_verified_at' => null]);
    URL::forceRootUrl('https://plaat.club');
    URL::forceScheme('https');
    $oldUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->public_id,
        'hash' => sha1($user->email),
    ]);
    URL::forceRootUrl(null);
    URL::forceScheme(null);
    $newUrl = str_replace('https://plaat.club', 'https://plaatclub.com', $oldUrl);

    $this->get($oldUrl)->assertRedirect($newUrl);
    $this->actingAs($user)->get($newUrl)->assertRedirect('/friends');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
