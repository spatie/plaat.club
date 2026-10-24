<?php

use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

it('shows local demo login controls only on the login page', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.social_demo' => true]);
    $alex = socialUser('alex');

    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('demoUsers', 1)->where('demoUsers.0.email', $alex->email)
        ->where('demoUsers.0.url', route('loginLinkLogin', absolute: false)));

    foreach (['/', '/register', '/forgot-password', '/reset-password/preview-token'] as $path) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('demoUsers', []));
    }
    $this->get('/people/alex')->assertRedirect('/login');

    $this->actingAs($alex);
    foreach (['/people/alex', '/friends', '/settings'] as $path) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->where('demoUsers', []));
    }

    $alex->update(['email_verified_at' => null]);
    $this->get('/verify-email')->assertOk()->assertInertia(fn (Assert $page) => $page->where('demoUsers', []));
});

it('switches existing local demo accounts without passwords and ignores forged package selectors', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.social_demo' => true]);
    $alex = socialUser('alex');
    $robin = socialUser('robin');
    $freek = User::freek();
    $freek->update(['email' => 'freek@example.test']);
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('demoUsers', 3)->where('demoUsers.0.label', 'Alex')->where('demoUsers.1.label', 'Robin')->where('demoUsers.2.label', 'Freek'));

    $this->withSession(['_token' => 'demo-login'])->post(route('loginLinkLogin'), ['email' => $alex->email, '_token' => 'demo-login'])->assertRedirect('/friends');
    $this->assertAuthenticatedAs($alex);
    $this->get('/friends')->assertOk();

    $forged = ['key' => User::freek()->id, 'user_attributes' => json_encode(['email' => User::freek()->email]), 'user_model' => 'AnUntrustedModel', 'guard' => 'not-a-guard', 'redirect_url' => 'https://untrusted.example'];
    $this->withSession(['_token' => 'demo-login'])->post(route('loginLinkLogin'), [...$forged, 'email' => $robin->email, '_token' => 'demo-login'])->assertRedirect('/friends');
    $this->assertAuthenticatedAs($robin);
    $this->get('/friends')->assertOk();

    $this->withSession(['_token' => 'demo-login'])->post(route('loginLinkLogin').'?'.http_build_query($forged), ['email' => $alex->email, '_token' => 'demo-login'])->assertRedirect('/friends');
    $this->assertAuthenticatedAs($alex);
    $this->get('/friends')->assertOk();

    $this->withSession(['_token' => 'demo-login'])->post(route('loginLinkLogin'), ['email' => $freek->email, '_token' => 'demo-login'])->assertRedirect('/friends');
    $this->assertAuthenticatedAs($freek);
});

it('does not allow login links for Freek, arbitrary accounts or missing demo accounts', function ($email) {
    app()->detectEnvironment(fn () => 'local');
    config(['services.social_demo' => true]);
    socialUser('outsider');
    $this->withSession(['_token' => 'demo-login'])->post(route('loginLinkLogin'), ['email' => $email, '_token' => 'demo-login'])->assertNotFound();
    $this->assertGuest();
    $this->assertDatabaseCount('users', 2);
})->with(['freek@spatie.be', 'outsider@example.test', 'alex@example.test', null, ['alex@example.test']]);

it('hides and disables login links outside explicitly enabled local demos', function (string $environment, bool $enabled) {
    app()->detectEnvironment(fn () => $environment);
    config(['services.social_demo' => $enabled]);
    $alex = socialUser('alex');
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('demoUsers', []));
    $this->withSession(['_token' => 'demo-login'])->post(route('loginLinkLogin'), ['email' => $alex->email, '_token' => 'demo-login'])->assertNotFound();
    $this->assertGuest();
})->with([['production', true], ['staging', true], ['testing', true], ['local', false]]);

it('renders account emails with a shared inline layout and a working action link', function (string $notification, string $actionPath) {
    $user = socialUser('mailrecipient');
    $message = $notification === 'verify' ? new VerifyEmail : new ResetPassword('preview-token');
    $html = (string) $message->toMail($user)->render();

    expect($html)->toContain(config('app.name'), 'class="inner-body"', '#f5f1e7', $actionPath)
        ->not->toContain('<link rel="stylesheet"');
})->with([['verify', '/verify-email/'], ['reset', '/reset-password/preview-token']]);

it('renders a branded verification email with the same signed link and expiry in HTML and plain text', function () {
    config(['auth.verification.expire' => 37]);
    $user = socialUser('verificationmail');
    $user->update(['email_verified_at' => null]);
    $message = (new VerifyEmail)->toMail($user);
    $html = (string) $message->render();
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
    $url = $message->viewData['url'];

    expect($html)->toContain('ALMOST', 'One more spin.', 'Verify my email', '#d9ed92', '#d3c0ea', $user->email, '37 minutes')
        ->and(html_entity_decode($html))->toContain('href="'.$url.'"')
        ->and($text)->toContain('Verify my email: '.$url, '37 minutes')->not->toContain('<table', '<style');
    $this->actingAs($user)->get($url)->assertRedirect('/friends');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('renders the new collector email with escaped account details and an admin link in both formats', function () {
    $user = socialUser('newcollector');
    $user->update(['name' => '<b>Robin & Alex</b>']);
    $message = (new NewUserRegistered($user))->toMail($user);
    $html = (string) $message->render();
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
    $url = url("admin/users/{$user->public_id}");

    expect($html)->toContain('NEW', 'COMPANY.', '#d9ed92', '#d3c0ea', '&lt;b&gt;Robin &amp; Alex&lt;/b&gt;', '@newcollector', $user->email, 'href="'.$url.'"')
        ->not->toContain('<b>Robin & Alex</b>', '<link rel="stylesheet"')
        ->and($text)->toContain($user->name, '@newcollector', $user->email, 'View user in admin: '.$url)
        ->not->toContain('<table', '<style');
});

it('registers a friends-only account, verifies the inbox, and authenticates securely', function () {
    Notification::fake();
    $this->post('/register', ['name' => 'New Collector', 'handle' => 'New_Collector', 'email' => 'NEW@example.test', 'password' => 'a-long-demo-password', 'password_confirmation' => 'a-long-demo-password', 'visibility' => 'everyone', 'email_verified_at' => now(), 'legacy_owner' => true, 'google_id' => 'forged-google-identity'])->assertRedirect('/verify-email');
    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    expect($user->handle)->toBe('new_collector')->and($user->visibility)->toBe('friends')->and($user->legacy_owner)->toBeFalse()->and($user->email_verified_at)->toBeNull()->and($user->digest_enabled)->toBeTrue()->and($user->google_id)->toBeNull();
    expect(Hash::check('a-long-demo-password', $user->password))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertSentOnDemand(NewUserRegistered::class, fn ($notification, $channels, $notifiable) => $notification->user->is($user) && $notifiable->routes['mail'] === 'freek@spatie.be');
    $this->get('/friends')->assertRedirect('/verify-email');
    $this->get('/verify-email')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/AuthPage')->where('mode', 'verify')->where('auth.user.email', $user->email)
        ->where('auth.user.verified', false));
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->public_id, 'hash' => sha1($user->email)]))->assertRedirect('/friends');
    $this->get('/friends')->assertOk()->assertViewHas('page.encryptHistory', true);
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
    $this->get('/')->assertViewHas('page.clearHistory', true);
    $this->get('/settings')->assertRedirect('/login');
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-demo-password'])->assertRedirect('/settings');
    $this->assertAuthenticatedAs($user);
    Notification::assertSentOnDemandTimes(NewUserRegistered::class, 1);
    foreach (['registered', 'email_verified', 'logout'] as $event) {
        $this->assertDatabaseHas('activity_log', ['event' => $event, 'causer_id' => $user->id, 'subject_id' => $user->id]);
    }
    $this->assertDatabaseCount('activity_log', 5);
    expect(Activity::query()->where('event', 'login')->count())->toBe(2);
    expect(Activity::all()->toJson())->not->toContain('a-long-demo-password', 'remember_token');
});

it('registers with only an email and password and lets the username change in settings', function () {
    Notification::fake();
    $this->post('/register', [
        'email' => ' NEW.COLLECTOR@example.test ',
        'password' => 'a-long-demo-password',
        'visibility' => 'everyone', 'legacy_owner' => true, 'email_verified_at' => now(),
    ])->assertRedirect('/verify-email');

    $user = User::query()->where('email', 'new.collector@example.test')->firstOrFail();
    expect($user->name)->toBe('New Collector')->and($user->handle)->toBe('new_collector')
        ->and($user->visibility)->toBe('friends')->and($user->legacy_owner)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()->and($user->digest_enabled)->toBeTrue()
        ->and(Hash::check('a-long-demo-password', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/settings')->assertRedirect('/verify-email');
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->public_id, 'hash' => sha1($user->email)]))->assertRedirect('/settings');
    $this->patch('/settings', [
        'name' => 'The record collector', 'handle' => ' Chosen_Handle ', 'visibility' => 'only_me',
        'comments_enabled' => true, 'digest_enabled' => true,
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->handle)->toBe('chosen_handle')->and($user->fresh()->name)->toBe('The record collector');
    $this->get('/people/chosen_handle')->assertOk()->assertSee('The record collector');
    $this->patch('/settings', [
        'name' => str_repeat('x', 81), 'handle' => 'chosen_handle', 'visibility' => 'only_me',
        'comments_enabled' => true, 'digest_enabled' => true,
    ])->assertSessionHasErrors('name');
    expect($user->fresh()->name)->toBe('The record collector');
    $this->patch('/settings', [
        'handle' => '', 'visibility' => 'only_me',
        'comments_enabled' => true, 'digest_enabled' => true,
    ])->assertSessionHasErrors('handle');
});

it('opens the signed-in collection by default after login while preserving verification', function (bool $verified, string $destination) {
    $user = socialUser('defaultcollection');
    if (! $verified) {
        $user->update(['email_verified_at' => null]);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-demo-password'])->assertRedirect('/');
    $this->get('/')->assertRedirect($destination);
    $this->assertAuthenticatedAs($user);
})->with([[true, '/defaultcollection'], [false, '/verify-email']]);

it('generates valid unique usernames from unusual, reserved and long email local parts', function (string $localPart) {
    Notification::fake();
    $handles = [];

    foreach (['example.test', 'another.test'] as $domain) {
        $email = "{$localPart}@{$domain}";
        $this->post('/register', [
            'email' => $email, 'name' => ' ', 'handle' => ' ',
            'password' => 'a-long-demo-password', 'password_confirmation' => 'a-long-demo-password',
        ])->assertRedirect('/verify-email');

        $user = User::query()->where('email', $email)->firstOrFail();
        expect($user->handle)->toMatch('/^[a-z0-9_]{3,30}$/')->not->toBeIn(User::RESERVED_HANDLES)
            ->not->toBe('freek')->and($user->name)->not->toBeEmpty()
            ->and(mb_strlen($user->name))->toBeLessThanOrEqual(80);
        $handles[] = $user->handle;
        $this->post('/logout')->assertRedirect('/');
    }

    expect($handles[0])->not->toBe($handles[1]);
})->with(['collector', 'a', '---', 'élodie', '你好', 'freek', 'settings', str_repeat('a', 64)]);

it('keeps validation for explicit profile fields and strong passwords during email-first signup', function (array $invalid, array $errors) {
    $this->post('/register', [
        'email' => 'collector@example.test',
        'password' => 'a-long-demo-password',
        ...$invalid,
    ])->assertSessionHasErrors($errors);
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'collector@example.test']);
})->with([
    [['name' => str_repeat('a', 81)], ['name']],
    [['name' => ['invalid']], ['name']],
    [['handle' => 'ab'], ['handle']],
    [['handle' => str_repeat('a', 31)], ['handle']],
    [['handle' => 'not-valid'], ['handle']],
    [['handle' => 'freek'], ['handle']],
    [['email' => ' FREEK@spatie.be '], ['email']],
    [['password' => 'short'], ['password']],
    [['password' => null], ['password']],
]);

it('reserves Freek’s identity and rejects duplicate or invalid handles and weak passwords', function () {
    $data = ['name' => 'Someone', 'handle' => 'freek', 'email' => 'other@example.test', 'password' => 'short', 'password_confirmation' => 'short'];
    $this->post('/register', $data)->assertSessionHasErrors(['handle', 'password']);
    $this->post('/register', [...$data, 'handle' => 'valid_handle', 'email' => 'FREEK@spatie.be'])->assertSessionHasErrors('email');
    $this->post('/register', [...$data, 'handle' => '<invalid>'])->assertSessionHasErrors('handle');
    expect(User::freek()->hasVerifiedEmail())->toBeTrue();
});

it('requires inbox verification throughout the app while keeping verification and sign-out available', function () {
    Notification::fake();
    $user = socialUser('unverified');
    $user->update(['email_verified_at' => null]);
    $public = socialUser('public_collector', 'everyone');
    $record = socialRecord($public);

    $this->actingAs($user);
    foreach (['/', '/friends', '/friends/manage', '/settings', '/notifications', '/collections', '/community', '/unverified', '/people/unverified', "/shelves/{$record->public_id}", '/public_collector'] as $path) {
        $this->get($path)->assertRedirect('/verify-email');
    }
    $this->get('/verify-email')->assertOk();
    $this->from('/verify-email')->post('/email/verification-notification')->assertRedirect('/verify-email')->assertSessionMissing('status');
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->public_id, 'hash' => 'wrong']))->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->public_id, 'hash' => sha1($user->email)]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->public_id, 'hash' => sha1($user->email)]))->assertRedirect('/public_collector');
    $this->get('/public_collector')->assertOk();

    $user->update(['email_verified_at' => null]);
    $this->post('/logout')->assertRedirect('/');
    $this->get('/collections')->assertOk();
    $this->get('/public_collector')->assertOk();
});

it('resets passwords through a single-use expiring email token', function () {
    Notification::fake();
    $user = socialUser('reset');
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class);
    $token = Password::createToken($user);
    $data = ['email' => $user->email, 'token' => $token, 'password' => 'new-long-password', 'password_confirmation' => 'new-long-password'];
    $this->post('/reset-password', [...$data, 'token' => 'invalid'])->assertSessionHasErrors('email');
    $this->post('/reset-password', $data)->assertRedirect('/login');
    expect(Hash::check('new-long-password', $user->fresh()->password))->toBeTrue();
    $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    $token = Password::createToken($user);
    $this->travel(61)->minutes();
    $this->post('/reset-password', [...$data, 'token' => $token])->assertSessionHasErrors('email');
});

it('reserves top-level routes and assets in registration and settings', function (string $handle) {
    $this->post('/register', [
        'name' => 'Collector', 'handle' => strtoupper($handle), 'email' => 'reserved@example.test',
        'password' => 'a-long-demo-password', 'password_confirmation' => 'a-long-demo-password',
    ])->assertSessionHasErrors('handle');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'reserved@example.test']);

    $user = socialUser('validcollector');
    $this->actingAs($user)->patch('/settings', [
        'name' => $user->name, 'handle' => strtoupper($handle), 'visibility' => 'everyone',
        'comments_enabled' => true, 'digest_enabled' => true,
    ])->assertSessionHasErrors('handle');
    expect($user->fresh()->handle)->toBe('validcollector');
})->with(array_unique(['about', ...User::RESERVED_HANDLES]));

it('updates only allowed profile fields and keeps the legacy collection public', function () {
    $user = socialUser('settings');
    $this->actingAs($user)->patch('/settings', ['name' => 'New name', 'handle' => 'Changed', 'visibility' => 'friends', 'comments_enabled' => false, 'digest_enabled' => false, 'email' => 'stolen@example.test', 'legacy_owner' => true, 'google_id' => 'forged-google-identity'])->assertSessionHasNoErrors();
    expect($user->fresh()->handle)->toBe('changed')->and($user->fresh()->comments_enabled)->toBeFalse()->and($user->fresh()->digest_enabled)->toBeFalse()->and($user->fresh()->email)->toBe('settings@example.test')->and($user->fresh()->google_id)->toBeNull();
    expect($user->fresh()->name)->toBe('New name');
    $this->get('/people/changed')->assertOk()->assertSee('New name');
    $this->post('/logout');
    $freek = User::freek();
    $freek->update(['email_verified_at' => now()]);
    $this->actingAs($freek)->patch('/settings', ['name' => $freek->name, 'handle' => 'freek', 'visibility' => 'only_me', 'comments_enabled' => true, 'digest_enabled' => true])->assertSessionHasErrors('visibility');
});

it('provisions Freek only via his reserved inbox without leaking a password or token', function () {
    Notification::fake();
    $password = User::freek()->password;
    $this->artisan('social:provision-freek')->assertSuccessful();
    Notification::assertSentTo(User::freek(), ResetPassword::class);
    expect(User::freek()->password)->toBe($password);
});

it('seeds repeatable real catalog local data without touching Freek and refuses production seeding', function () {
    Notification::fake();
    Http::preventStrayRequests();
    app()->detectEnvironment(fn () => 'local');
    $password = User::freek()->password;
    $this->artisan('social:seed-demo')->assertSuccessful();
    $this->artisan('social:seed-demo')->assertSuccessful();
    $this->assertDatabaseCount('users', 6);
    $this->assertDatabaseCount('collection_records', 300);
    $this->assertDatabaseCount('friendships', 4);
    $this->assertDatabaseCount('social_notifications', 3);
    $this->assertDatabaseCount('comments', 1);
    $this->assertDatabaseCount('appreciations', 1);
    $alex = User::query()->where('handle', 'alex')->firstOrFail();
    expect($alex->friends()->pluck('handle')->sort()->values()->all())->toBe(['casey', 'robin', 'sam'])
        ->and($alex->records()->where('instance_id', 900030)->firstOrFail()->private_note)->toContain('birthday gift')
        ->and(User::query()->where('handle', 'jamie')->firstOrFail()->records()->count())->toBe(0);
    expect(User::freek()->password)->toBe($password);
    foreach (['alex' => 120, 'robin' => 90, 'sam' => 60, 'casey' => 30] as $handle => $count) {
        $records = User::query()->where('handle', $handle)->firstOrFail()->records()->get();
        expect($records)->toHaveCount($count)
            ->and($records->pluck('payload.artist')->unique()->count())->toBeGreaterThan(15)
            ->and($records->where('is_activity', true)->count())->toBeGreaterThan(0)
            ->and($records->where('is_activity', false)->count())->toBeGreaterThan(0);
        foreach (['Vinyl', 'CD', 'Cassette'] as $format) {
            expect($records->contains(fn ($record) => in_array($format, $record->formats(), true)))->toBeTrue();
        }
        foreach ($records as $record) {
            expect($record->payload['id'])->toBe($record->discogs_release_id)
                ->and(is_file(public_path($record->payload['cover'])))->toBeTrue()
                ->and($record->payload['tracks'])->not->toBeEmpty();
        }
    }
    expect(User::query()->where('handle', 'casey')->first()->visibility)->toBe('only_me');
    $this->actingAs(User::query()->where('handle', 'jamie')->firstOrFail())->get('/friends')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('collectionCount', 0)
        ->where('auth.user.discogsConnected', false)->where('auth.user.importedAt', null));
    Notification::assertNothingSent();
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('social:seed-demo')->assertFailed();
});

it('rate limits account submissions and permits them again after the window', function (string $path) {
    foreach (range(1, 6) as $attempt) {
        $this->postJson($path, [])->assertUnprocessable();
    }
    $this->postJson($path, [])->assertStatus(429)->assertHeader('Retry-After');
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])->postJson($path, [])->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson($path, [])->assertUnprocessable();
})->with(['/login', '/register', '/forgot-password', '/reset-password']);
