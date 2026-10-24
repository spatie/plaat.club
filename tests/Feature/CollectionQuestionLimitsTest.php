<?php

use App\Actions\Collections\ConsumeCollectionQuestionBudgetAction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'services.openai.daily_user_limit' => 2,
        'services.openai.daily_global_limit' => 10,
        'services.openai.daily_limit_timezone' => 'Europe/Brussels',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
});

it('reserves independent personal allowances and names the exact reset when exhausted', function () {
    $viewer = socialUser('budget_viewer');
    $other = socialUser('budget_other');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toBe('You have reached your personal daily AI question limit. It resets at 2026-10-25 00:00:00 +02:00 Europe/Brussels.')
        ->and($action->execute($other))->toBeNull()
        ->and($action->execute($other))->toBeNull();
});

it('enforces one shared allowance across different users', function () {
    config(['services.openai.daily_global_limit' => 2]);
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    expect($action->execute(socialUser('budget_first')))->toBeNull()
        ->and($action->execute(socialUser('budget_second')))->toBeNull()
        ->and($action->execute(socialUser('budget_third')))->toBe('The shared daily AI question limit has been reached. It resets at 2026-10-25 00:00:00 +02:00 Europe/Brussels.');
});

it('uses a persisted personal override without changing another users allowance', function () {
    config(['services.openai.daily_global_limit' => 6000]);
    $viewer = socialUser('budget_override');
    $viewer->update(['ai_daily_question_limit' => 5000]);
    $viewer = $viewer->fresh();
    $other = socialUser('budget_default');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);
    Cache::put("collection-questions:2026-10-24:user:{$viewer->id}", 4999);

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('personal daily')
        ->and($action->execute($other))->toBeNull()
        ->and($action->execute($other))->toBeNull()
        ->and($action->execute($other))->toContain('personal daily');
});

it('still enforces the shared cap for users with a personal override', function () {
    config(['services.openai.daily_global_limit' => 1]);
    $viewer = socialUser('budget_override');
    $viewer->update(['ai_daily_question_limit' => 5000]);
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('shared daily')
        ->and(Cache::get("collection-questions:2026-10-24:user:{$viewer->id}"))->toBe(1);
});

it('allows a zero personal override to disable questions for one user', function () {
    $viewer = socialUser('budget_disabled');
    $viewer->update(['ai_daily_question_limit' => 0]);
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    expect($action->execute($viewer))->toContain('personal daily')
        ->and(Cache::get('collection-questions:2026-10-24:global'))->toBeNull()
        ->and($action->execute(socialUser('budget_enabled')))->toBeNull();
});

it('does not consume shared allowance when a personal limit rejects a question', function () {
    config(['services.openai.daily_user_limit' => 1, 'services.openai.daily_global_limit' => 2]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('personal daily')
        ->and($action->execute($viewer))->toContain('personal daily')
        ->and($action->execute(socialUser('budget_other')))->toBeNull();
});

it('does not consume personal allowance when the shared limit rejects a question', function () {
    config(['services.openai.daily_user_limit' => 1, 'services.openai.daily_global_limit' => 1]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);
    $action->execute(socialUser('budget_other'));

    expect($action->execute($viewer))->toContain('shared daily')
        ->and($action->execute($viewer))->toContain('shared daily');

    config(['services.openai.daily_global_limit' => 2]);

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('personal daily');
});

it('resets both counters exactly at Brussels midnight rather than UTC midnight', function () {
    config(['services.openai.daily_user_limit' => 1, 'services.openai.daily_global_limit' => 1]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 21:59:59', 'UTC'));

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('2026-10-25 00:00:00 +02:00 Europe/Brussels');

    $this->travelTo(CarbonImmutable::parse('2026-10-24 22:00:00', 'UTC'));

    expect(Cache::get("collection-questions:2026-10-24:user:{$viewer->id}"))->toBeNull()
        ->and(Cache::get('collection-questions:2026-10-24:global'))->toBeNull()
        ->and($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('2026-10-26 00:00:00 +01:00 Europe/Brussels');
});

it('uses the next local midnight and expires counters across daylight saving changes', function (string $now, string $reset, string $resetUtc) {
    config(['services.openai.daily_user_limit' => 1, 'services.openai.daily_global_limit' => 1]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    $date = CarbonImmutable::now('Europe/Brussels')->toDateString();

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain($reset);

    $this->travelTo(CarbonImmutable::parse($resetUtc, 'UTC')->subSecond());

    expect($action->execute($viewer))->toContain('personal daily');

    $this->travelTo(CarbonImmutable::parse($resetUtc, 'UTC'));

    expect(Cache::get("collection-questions:{$date}:user:{$viewer->id}"))->toBeNull()
        ->and(Cache::get("collection-questions:{$date}:global"))->toBeNull()
        ->and($action->execute($viewer))->toBeNull();
})->with([
    'spring forward' => ['2026-03-28 23:00:00', '2026-03-30 00:00:00 +02:00 Europe/Brussels', '2026-03-29 22:00:00'],
    'fall back' => ['2026-10-24 22:00:00', '2026-10-26 00:00:00 +01:00 Europe/Brussels', '2026-10-25 23:00:00'],
]);

it('disables questions when either configured limit is zero', function (string $setting, string $budget) {
    config(["services.openai.{$setting}" => 0]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    expect($action->execute($viewer))->toContain("{$budget} daily")
        ->and(Cache::get("collection-questions:2026-10-24:user:{$viewer->id}"))->toBeNull()
        ->and(Cache::get('collection-questions:2026-10-24:global'))->toBeNull();
})->with([
    'personal limit' => ['daily_user_limit', 'personal'],
    'shared limit' => ['daily_global_limit', 'shared'],
]);

it('uses the default personal limit and Brussels timezone when configuration is absent', function () {
    config(['services.openai' => []]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);

    foreach (range(1, 25) as $question) {
        expect($action->execute($viewer))->toBeNull();
    }

    expect($action->execute($viewer))->toBe('You have reached your personal daily AI question limit. It resets at 2026-10-25 00:00:00 +02:00 Europe/Brussels.');
});

it('uses the default shared limit when configuration is absent', function () {
    config(['services.openai' => ['daily_user_limit' => 1001]]);
    $viewer = socialUser('budget_viewer');
    $action = app(ConsumeCollectionQuestionBudgetAction::class);
    Cache::put('collection-questions:2026-10-24:global', 999);

    expect($action->execute($viewer))->toBeNull()
        ->and($action->execute($viewer))->toContain('shared daily');
});

it('honors a configured reset timezone', function () {
    config(['services.openai.daily_user_limit' => 0, 'services.openai.daily_limit_timezone' => 'America/New_York']);

    expect(app(ConsumeCollectionQuestionBudgetAction::class)->execute(socialUser('budget_viewer')))
        ->toContain('2026-10-25 00:00:00 -04:00 America/New_York');
});

it('fails closed without consuming either budget when the global lock times out', function () {
    $viewer = socialUser('budget_viewer');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(2, Mockery::type('callable'))->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->with('collection-questions:budget-lock', 10)->andReturn($lock);

    expect(app(ConsumeCollectionQuestionBudgetAction::class)->execute($viewer))
        ->toBe('AI questions are temporarily busy. Please try again in a few seconds.');
});
