<?php

namespace App\Providers;

use App\Listeners\FulfillInvitationsListener;
use App\Listeners\LogAuthenticationActivityListener;
use App\Listeners\LogCollectionQuestionListener;
use App\Listeners\SendNewUserMailListener;
use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        config(['activitylog.activity_model' => Activity::class]);
        config(['ai.providers.openai.store' => false]);

        VerifyEmail::createUrlUsing(fn (User $user): string => URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            ['id' => $user->public_id, 'hash' => sha1($user->getEmailForVerification())],
        ));

        Event::listen(Registered::class, [FulfillInvitationsListener::class, 'fulfill']);
        Event::listen(Registered::class, [SendNewUserMailListener::class, 'send']);
        Event::listen(Verified::class, [FulfillInvitationsListener::class, 'fulfill']);
        Event::listen([Login::class, Logout::class, Registered::class, Verified::class, PasswordReset::class], [LogAuthenticationActivityListener::class, 'log']);
        Event::listen([AgentPrompted::class, AgentFailed::class], [LogCollectionQuestionListener::class, 'log']);

        VerifyEmail::toMailUsing(fn (User $user, string $url): MailMessage => (new MailMessage)
            ->subject('One more spin. Verify your Plaat Club email.')
            ->theme('friend-request-theme')
            ->markdown('mail.verify-email', [
                'email' => $user->email,
                'url' => $url,
                'expires' => config('auth.verification.expire', 60),
            ]));

        DB::prohibitDestructiveCommands(app()->isProduction());

        RateLimiter::for('shareImages', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('collectionQuestions', fn (Request $request): Limit => $request->routeIs('converse.web.send')
            ? Limit::perMinute(10)->by($request->user()->id)
            : Limit::none());
    }
}
