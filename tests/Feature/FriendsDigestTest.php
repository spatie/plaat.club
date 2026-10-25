<?php

use App\Actions\Collections\StoreCollectionRecordAction;
use App\Actions\Social\SendFriendsDigestAction;
use App\Mail\FriendsWeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

it('shows a public email example using the real template and only unsaved fictional records', function () {
    Mail::fake();
    $owner = socialUser('preview_private', 'only_me');
    socialRecord($owner, 'Never show this private record');
    $this->get('/about/weekly-email')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('social/WeeklyEmailPage')
        ->where('emailHtml', fn (string $html) => str_contains(strip_tags($html), 'SundayMorning.')
            && str_contains($html, 'Midnight Windows') && str_contains($html, 'Sunday Static')
            && substr_count($html, 'src="data:image/jpeg;base64,') === 3 && ! str_contains($html, 'cid:')
            && ! str_contains($html, 'Never show this private record') && ! str_contains($html, 'preview_private')));
    $this->assertDatabaseCount('collection_records', 1);
    Mail::assertNothingSent();
});

it('sends the latest completed Sunday morning week in original addition order without duplicates', function (string $runAt) {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse($runAt));
    $viewer = socialUser('viewer');
    $owner = socialUser('owner', 'friends');
    socialFriends($viewer, $owner);
    socialRecord($owner, 'Before window', true, '2026-10-11 06:59:59', 1);
    socialRecord($owner, 'Window start', true, '2026-10-11 07:00:00', 2)->update(['shared_note' => 'Owner shared note', 'private_note' => 'Owner private note']);
    socialRecord($owner, 'Window end minus one', true, '2026-10-18 06:59:59', 3);
    socialRecord($owner, 'Next week', true, '2026-10-18 07:00:00', 4);
    socialRecord($owner, 'Initial import', false, '2026-10-17 12:00:00', 5);
    $this->artisan('social:send-digests')->assertSuccessful();
    Mail::assertSent(FriendsWeeklyDigest::class, function (FriendsWeeklyDigest $mail) use ($viewer) {
        expect($mail->records->pluck('payload.displayTitle')->all())->toBe(['Window end minus one', 'Window start']);
        $html = $mail->render();
        expect($html)->toContain('Window start', '/owner/records/', '/settings', 'class="inner-body"', 'background-color:', 'See friends’ additions')
            ->not->toContain('Initial import', 'Before window', 'Next week', 'Owner private note');

        return $mail->hasTo($viewer->email);
    });
    Mail::assertSentCount(1);
    $this->artisan('social:send-digests')->assertSuccessful();
    Mail::assertSentCount(1);
})->with([
    'Sunday at nine' => '2026-10-18T09:00:00+02:00',
    'Sunday afternoon' => '2026-10-18T15:30:00+02:00',
    'Monday rerun' => '2026-10-19T09:00:00+02:00',
    'Saturday rerun' => '2026-10-24T12:00:00+02:00',
    'before the next Sunday cutoff' => '2026-10-25T08:59:59+01:00',
]);

it('delivers styled and plain text digests without interpreting collector metadata as markup', function () {
    $owner = socialUser('emailmetadata', 'everyone');
    $owner->update(['name' => '[Not a link](https://untrusted.example) <b>Collector</b>']);
    $record = socialRecord($owner, '<script>alert("title")</script> [Album](https://untrusted.example)');
    $record->update(['private_note' => 'Never include this private note']);
    $sent = Mail::mailer('array')->send((new FriendsWeeklyDigest($owner->records()->get()))->to('digest@example.test'));
    $message = $sent->getSymfonySentMessage()->getOriginalMessage();

    expect($message->getSubject())->toBe('Sunday Morning · Plaat Club');
    expect(html_entity_decode(strip_tags($message->getHtmlBody())))->toContain($owner->name, $record->payload['displayTitle']);
    expect($message->getHtmlBody())->toContain('class="inner-body"', '#d84435', '#d9ed92', 'src="cid:', 'Album cover:')
        ->not->toContain('<script>', '<b>Collector</b>', 'href="https://untrusted.example"', 'Never include this private note', '↗');
    expect($message->getTextBody())->toContain('Sunday Morning · Plaat Club', $owner->name, $record->payload['displayTitle'], $record->url(), '/settings')
        ->not->toContain('<p', '<table', 'Never include this private note');
    $covers = $message->getAttachments();
    expect($covers)->toHaveCount(1);
    expect($covers[0]->getDisposition())->toBe('inline');
    expect($covers[0]->getMediaType().'/'.$covers[0]->getMediaSubtype())->toBe('image/jpeg');
    expect(getimagesizefromstring($covers[0]->getBody())[0])->toBeLessThanOrEqual(320);
    expect(strlen($covers[0]->getBody()))->toBeLessThan(100_000);
});

it('keeps a readable digest when artwork is absent, unavailable or untrusted', function (string $source) {
    Http::fake(['*' => Http::response('', 404)]);
    $owner = socialUser('missingart', 'everyone');
    $record = socialRecord($owner, 'The music still matters');
    app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload, 'cover' => $source]]);
    $sent = Mail::mailer('array')->send((new FriendsWeeklyDigest($owner->records()->get()))->to('digest@example.test'));
    $message = $sent->getSymfonySentMessage()->getOriginalMessage();

    expect($message->getHtmlBody())->toContain('The music still matters', 'NO SLEEVE.', 'STILL GOOD.', $record->url())
        ->not->toContain('src="cid:', $source ?: 'src=""');
    expect($message->getAttachments())->toBeEmpty();
    if (! str_starts_with($source, 'https://i.discogs.com/')) {
        Http::assertNothingSent();
    }
})->with(['no artwork' => '', 'missing Discogs artwork' => 'https://i.discogs.com/missing.jpg', 'untrusted host' => 'https://untrusted.example/cover.jpg']);

it('rechecks access and email preferences after downloading artwork', function (string $change, bool $sendRemaining) {
    Mail::fake();
    $this->travelTo(now()->setDate(2026, 10, 19)->setTime(7, 0));
    $viewer = socialUser('viewer');
    $owner = socialUser('changing', 'friends');
    $other = socialUser('unchanged', 'friends');
    $friendship = socialFriends($viewer, $owner);
    socialFriends($viewer, $other);
    $hidden = socialRecord($owner, 'Withdrawn while fetching');
    $visible = socialRecord($other, 'Still shared');
    app(StoreCollectionRecordAction::class)->execute($hidden, ['payload' => [...$hidden->payload, 'cover' => '/images/record-placeholder.svg', 'coverSource' => 'https://i.discogs.com/digest.jpg']]);
    Http::fake(['https://i.discogs.com/digest.jpg' => function () use ($change, $owner, $viewer, $friendship) {
        match ($change) {
            'visibility' => $owner->update(['visibility' => 'only_me']),
            'friendship' => $friendship->delete(),
            'preferences' => $viewer->update(['digest_enabled' => false]),
            'verification' => $viewer->update(['email_verified_at' => null]),
        };

        return Http::response(file_get_contents(public_path('images/demo-sleeve-1.jpg')), 200);
    }]);

    app(SendFriendsDigestAction::class)->execute($viewer, now());

    Http::assertSentCount(1);
    Mail::assertSentCount($sendRemaining ? 1 : 0);
    if ($sendRemaining) {
        Mail::assertSent(FriendsWeeklyDigest::class, function (FriendsWeeklyDigest $mail) use ($visible, $hidden) {
            expect($mail->records->modelKeys())->toBe([$visible->id]);
            expect($mail->render())->toContain('Still shared')->not->toContain('Withdrawn while fetching', 'sleeve-'.$hidden->id.'.jpg');

            return true;
        });
    }
})->with(['visibility' => ['visibility', true], 'friendship' => ['friendship', true], 'preferences' => ['preferences', false], 'verification' => ['verification', false]]);

it('sends only once when another run completes during artwork preparation', function () {
    Mail::fake();
    $this->travelTo(now()->setDate(2026, 10, 19)->setTime(7, 0));
    $viewer = socialUser('viewer');
    $owner = socialUser('owner', 'friends');
    socialFriends($viewer, $owner);
    $record = socialRecord($owner, 'One delivery');
    app(StoreCollectionRecordAction::class)->execute($record, ['payload' => [...$record->payload, 'cover' => 'https://i.discogs.com/slow.jpg']]);
    $otherRunCompleted = false;
    Http::fake(['https://i.discogs.com/slow.jpg' => function () use ($viewer, &$otherRunCompleted) {
        if (! $otherRunCompleted) {
            $otherRunCompleted = true;
            app(SendFriendsDigestAction::class)->execute($viewer, now());
        }

        return Http::response(file_get_contents(public_path('images/demo-sleeve-1.jpg')), 200);
    }]);

    app(SendFriendsDigestAction::class)->execute($viewer, now());

    Mail::assertSentCount(1);
    Mail::assertSent(FriendsWeeklyDigest::class, fn ($mail) => $mail->hasTo($viewer->email));
    expect($viewer->fresh()->digest_week)->toBe(now()->toDateString());
});

it('rechecks privacy, friendship, preferences and verification at send time and suppresses empty weeks', function () {
    Mail::fake();
    $this->travelTo(now()->setDate(2026, 10, 19)->setTime(7, 0));
    $viewer = socialUser('viewer');
    $private = socialUser('private', 'friends');
    $removed = socialUser('removed', 'everyone');
    $pending = socialUser('pending', 'everyone');
    socialFriends($viewer, $private);
    $connection = socialFriends($removed, $viewer);
    socialRecord($private, 'Now private');
    socialRecord($removed, 'Unfriended');
    socialRecord($pending, 'Not accepted');
    $private->update(['visibility' => 'only_me']);
    $connection->delete();
    $this->artisan('social:send-digests')->assertSuccessful();
    Mail::assertNothingSent();

    $this->travel(7)->days();
    $private->update(['visibility' => 'friends']);
    socialRecord($private, 'Fresh but opted out', true, '2026-10-20 12:00:00', 456);
    $viewer->update(['digest_enabled' => false]);
    $this->artisan('social:send-digests')->assertSuccessful();
    Mail::assertNothingSent();
    $viewer->update(['digest_enabled' => true, 'email_verified_at' => null]);
    $this->artisan('social:send-digests')->assertSuccessful();
    Mail::assertNothingSent();
});

it('uses Sunday at nine in Brussels across both daylight saving changes', function (string $runAt, string $start, string $end) {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse($runAt));
    $viewer = socialUser('viewer');
    $owner = socialUser('owner', 'everyone');
    socialFriends($viewer, $owner);
    socialRecord($owner, 'Before window', true, CarbonImmutable::parse($start)->subSecond()->toDateTimeString(), 1);
    socialRecord($owner, 'Window start', true, $start, 2);
    socialRecord($owner, 'Window end minus one', true, CarbonImmutable::parse($end)->subSecond()->toDateTimeString(), 3);
    socialRecord($owner, 'Next week', true, $end, 4);
    $this->artisan('social:send-digests')->assertSuccessful();
    Mail::assertSent(FriendsWeeklyDigest::class, fn (FriendsWeeklyDigest $mail) => $mail->records->pluck('payload.displayTitle')->all() === ['Window end minus one', 'Window start']);
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'social:send-digests'));
    expect($event->expression)->toBe('0 9 * * 0')->and($event->timezone)->toBe('Europe/Brussels');
})->with([
    'spring forward' => ['2026-03-29T09:00:00+02:00', '2026-03-22 08:00:00', '2026-03-29 07:00:00'],
    'fall back' => ['2026-10-25T09:00:00+01:00', '2026-10-18 07:00:00', '2026-10-25 08:00:00'],
]);

it('previews only the requested local recipient without advancing checkpoints and rechecks privacy', function () {
    Mail::fake();
    app()->detectEnvironment(fn () => 'local');
    $viewer = socialUser('viewer');
    $owner = socialUser('owner', 'friends');
    socialFriends($viewer, $owner);
    socialRecord($owner, 'Recent preview record', true, now()->subDay()->toDateTimeString());
    $this->artisan('social:send-digests', ['--preview-for' => $viewer->id])->assertSuccessful();
    Mail::assertSent(FriendsWeeklyDigest::class, fn ($mail) => $mail->hasTo($viewer->email) && $mail->records->count() === 1);
    expect($viewer->fresh()->digest_week)->toBeNull();
    $owner->update(['visibility' => 'only_me']);
    $this->artisan('social:send-digests', ['--preview-for' => $viewer->id])->assertSuccessful();
    Mail::assertSentCount(1);
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('social:send-digests', ['--preview-for' => $viewer->id])->assertFailed();
    Mail::assertSentCount(1);
});
