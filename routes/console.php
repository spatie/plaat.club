<?php

use App\Models\DiscogsResponse;
use App\Models\SocialNotification;
use Illuminate\Support\Facades\Schedule;
use Laravel\Converse\Models\Thread;
use Laravel\Converse\Models\ThreadEvent;

Schedule::command('model:prune', ['--model' => [SocialNotification::class, DiscogsResponse::class, ThreadEvent::class, Thread::class]])
    ->hourly()->withoutOverlapping()->onOneServer();

// Clears this scheduler's local files, not other web instances' image stores.
Schedule::command('cache:clear images')->dailyAt('03:30')->withoutOverlapping();

Schedule::command('records:sync-discogs')
    ->dailyAt('04:00')
    ->timezone('Europe/Brussels')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('social:sync-collections')
    ->dailyAt('04:15')->timezone('Europe/Brussels')->withoutOverlapping()->onOneServer();

Schedule::command('social:sync-collections --quick')
    ->hourlyAt(30)->timezone('Europe/Brussels')->withoutOverlapping()->onOneServer();

Schedule::command('records:enrich-releases')
    ->dailyAt('05:00')->timezone('Europe/Brussels')->withoutOverlapping()->onOneServer();

Schedule::command('social:send-digests')
    ->weeklyOn(0, '09:00')->timezone('Europe/Brussels')->withoutOverlapping()->onOneServer();
