<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Converse\Actions\GetUserAction;
use Laravel\Converse\Concurrency\ThreadCoordinator;
use Laravel\Converse\Console\BotListCommand;
use Laravel\Converse\Console\BotMakeCommand;
use Laravel\Converse\Console\BotMiddlewareMakeCommand;
use Laravel\Converse\Console\ChatCommand;
use Laravel\Converse\Console\InstallCommand;
use Laravel\Converse\Console\ScheduleListCommand;
use Laravel\Converse\Console\ScheduleRunCommand;
use Laravel\Converse\Console\WebhookSecretCommand;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Drivers\Terminal\TerminalOutput;
use Laravel\Converse\Drivers\Web\WebChatOptions;
use Laravel\Converse\Drivers\Web\WebDriver;
use Laravel\Converse\Enums\WebTransport;
use Laravel\Converse\Http\Controllers\Web\WebChatCancelController;
use Laravel\Converse\Http\Controllers\Web\WebChatController;
use Laravel\Converse\Http\Controllers\Web\WebChatStreamController;
use Laravel\Converse\Http\Controllers\WebhookController;
use Laravel\Converse\Http\Middleware\ExpectsJsonOrEventStream;
use Laravel\Converse\Http\Requests\Web\WebChatStreamRequest;
use Laravel\Converse\Routing\CallbackRegistry;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\Scheduling\DestinationResolver;
use Laravel\Converse\Scheduling\ScheduleRegistrar;
use Laravel\Converse\Storage\DatabaseConversationStore;
use Laravel\Converse\Storage\DatabaseRunStore;
use Laravel\Converse\Support\AttachmentDownloader;
use Symfony\Component\Console\Output\ConsoleOutput;

class ConverseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(
            __DIR__.'/../config/converse.php', 'converse',
        );

        $this->app->singleton(ConverseManager::class, fn (Application $app): ConverseManager => new ConverseManager($app));

        $this->app->singleton(BotRegistrar::class);
        $this->app->singleton(ScheduleRegistrar::class);
        $this->app->singleton(ThreadContext::class);
        $this->app->singleton(AttachmentDownloader::class);
        $this->app->singleton(CallbackRegistry::class);
        $this->app->singleton(ConversationStore::class, DatabaseConversationStore::class);
        $this->app->singleton(RunStore::class, DatabaseRunStore::class);
        $this->app->singleton(RunWriters::class);
        $this->app->singleton(TerminalOutput::class, fn (): TerminalOutput => new TerminalOutput(new ConsoleOutput));

        $this->app->when([ThreadCoordinator::class, CallbackRegistry::class, DestinationResolver::class, GetUserAction::class])
            ->needs(Repository::class)
            ->give(fn (Application $app): Repository => $app->make('cache')->store(config('converse.cache.store')));
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->registerBots();
        $this->registerSchedules();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    protected function registerRateLimiters(): void
    {
        if (RateLimiter::limiter('converse-stream') !== null) {
            return;
        }

        RateLimiter::for('converse-stream', function (Request $request): Limit {
            $stream = WebChatStreamRequest::createFrom($request)->setContainer($this->app);

            if (! $this->followsStream($stream)) {
                return Limit::none();
            }

            return Limit::perMinute(10)->by(implode('|', [
                $stream->driver()->owner($stream, $stream->participant(), $stream->bot()) ?? $request->ip(),
                $stream->bot()->name(),
                $stream->connectionName(),
                $stream->threadId(),
            ]));
        });
    }

    protected function followsStream(WebChatStreamRequest $request): bool
    {
        return $request->following()
            && $this->app->make(BotRegistrar::class)->has((string) $request->route('bot'))
            && $request->bot()->hasConnection($request->connectionName())
            && $request->options()->transport() === WebTransport::Stream;
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach (WebDriver::connections() as $name => $config) {
            $this->registerWebRoutes($name, WebChatOptions::fromConfig($config)->middleware());
        }

        Route::match(['GET', 'POST'], '/converse/{bot}/{connection}', WebhookController::class)->name('converse.webhook');
    }

    /**
     * @param  list<string>  $middleware
     */
    protected function registerWebRoutes(string $connection, array $middleware): void
    {
        Route::prefix("converse/{bot}/{$connection}")
            ->name("converse.{$connection}.")
            ->middleware([ExpectsJsonOrEventStream::class, ...$middleware])
            ->where(['thread' => WebDriver::ID_PATTERN, 'run' => WebDriver::ID_PATTERN])
            ->group(function () use ($connection): void {
                Route::post('/', WebChatController::class)->defaults('connection', $connection)->name('send');
                Route::get('{thread}/stream', WebChatStreamController::class)->defaults('connection', $connection)->middleware(ThrottleRequests::using('converse-stream'))->name('stream');
                Route::post('{thread}/runs/{run}/cancel', WebChatCancelController::class)->defaults('connection', $connection)->name('cancel');
            });
    }

    protected function registerBots(): void
    {
        if (file_exists($bot = $this->app->basePath('routes/bot.php'))) {
            require $bot;
        }
    }

    protected function registerSchedules(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(ScheduleRegistrar::class)->validate();
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->app->make(ScheduleRegistrar::class)->register($schedule);
        });
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/converse.php' => config_path('converse.php'),
        ], 'converse-config');

        $this->publishes([
            __DIR__.'/../routes/bot.php' => base_path('routes/bot.php'),
        ], 'converse-bots');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'converse-migrations');
    }

    protected function registerCommands(): void
    {
        $this->commands([
            BotListCommand::class,
            BotMakeCommand::class,
            BotMiddlewareMakeCommand::class,
            ChatCommand::class,
            InstallCommand::class,
            ScheduleListCommand::class,
            ScheduleRunCommand::class,
            WebhookSecretCommand::class,
        ]);
    }
}
