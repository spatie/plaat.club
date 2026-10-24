<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Command as BotCommand;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Drivers\Terminal\Screen;
use Laravel\Converse\Drivers\Terminal\TerminalAction;
use Laravel\Converse\Drivers\Terminal\TerminalDriver;
use Laravel\Converse\Drivers\Terminal\TerminalOutput;
use Laravel\Converse\Drivers\Terminal\TerminalRenderer;
use Laravel\Converse\Enums\TerminalDisplay;
use Laravel\Converse\Exceptions\ConverseException;
use Laravel\Converse\Exceptions\InterruptedException;
use Laravel\Converse\Markdown;
use Laravel\Converse\Thread;
use Laravel\Prompts\AutoCompletePrompt;
use Laravel\Prompts\Elements\Element;
use Laravel\Prompts\Key;
use Throwable;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\clear;
use function Laravel\Prompts\select;
use function Laravel\Prompts\title;

#[Signature('converse:chat {bot? : The bot to talk to, optional when a single bot is registered}
                          {prompt? : Send a single message, print the reply, and leave}
                          {--connection=terminal : The connection to talk through}
                          {--thread= : Resume an earlier conversation by its id}
                          {--tools=full : How tool calls are shown: full, collapsed, or hidden}
                          {--reasoning=collapsed : How reasoning is shown: full, collapsed, or hidden}
                          {--context= : The model context window in tokens, to show how much of it each reply used}', aliases: ['agent:chat'])]
#[Description('Talk to one of your bots from the terminal')]
class ChatCommand extends Command
{
    /** @var array<int, string> */
    protected const array PARTING = ['exit', 'quit', '/exit', '/quit'];

    protected const int KEEP_TALKING = -1;

    /** @var array<string, string> */
    protected const array COMMANDS = [
        '/new' => 'Start a new conversation',
        '/id' => 'Show the id to resume this conversation with --thread',
        '/clear' => 'Clear the screen',
        '/tools full' => 'Show tool calls with their results',
        '/tools collapsed' => 'Show only the tool call',
        '/tools hidden' => 'Hide tool calls',
        '/reasoning full' => 'Show the reasoning text',
        '/reasoning collapsed' => 'Show only how long the reasoning was',
        '/reasoning hidden' => 'Hide reasoning',
        '/help' => 'List these commands',
        '/exit' => 'Leave, or press Esc',
    ];

    protected const int REPLAYED = 50;

    protected TerminalOutput $screen;

    protected string $thread;

    /** @var array{tools: TerminalDisplay, reasoning: TerminalDisplay} */
    protected array $displays;

    protected ?int $window = null;

    /** @var array<int, string> */
    protected array $history = [];

    protected int $tokens = 0;

    protected ?int $context = null;

    public function handle(BotRegistrar $registrar): int
    {
        $bot = $this->argument('bot') ?? $this->onlyBot($registrar);
        $connection = (string) $this->option('connection');

        if (! is_string($bot)) {
            $this->components->error('Pass the bot to talk to: ['.$registrar->bots()->keys()->implode(', ').'].');

            return static::FAILURE;
        }

        try {
            $driver = $registrar->connection($connection, $bot);
        } catch (ConverseException $converseException) {
            $this->components->error($converseException->getMessage());

            return static::FAILURE;
        }

        if (! $driver instanceof TerminalDriver) {
            $this->components->error("Connection [{$connection}] does not use the [terminal] driver.");

            return static::FAILURE;
        }

        $tools = TerminalDisplay::tryFrom((string) $this->option('tools'));
        $reasoning = TerminalDisplay::tryFrom((string) $this->option('reasoning'));

        if (! $tools instanceof TerminalDisplay || ! $reasoning instanceof TerminalDisplay) {
            $this->components->error('The [--tools] and [--reasoning] options accept [full], [collapsed], or [hidden].');

            return static::FAILURE;
        }

        $window = $this->option('context') === null ? null : filter_var($this->option('context'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($window === false) {
            $this->components->error('The [--context] option must be a positive number of tokens.');

            return static::FAILURE;
        }

        $this->displays = ['tools' => $tools, 'reasoning' => $reasoning];
        $this->window = $window;

        $this->laravel->instance(TerminalOutput::class, $this->screen = new TerminalOutput($this->output));

        $resumed = filled($this->option('thread'));

        $this->thread = $resumed ? (string) $this->option('thread') : (string) Str::uuid();

        $this->listen();

        AutoCompletePrompt::fallbackUsing(fn (AutoCompletePrompt $prompt): mixed => $this->promptUntilValid(
            fn (): mixed => $this->components->ask($prompt->label) ?? '',
            $prompt->required,
            $prompt->validate,
        ));

        config(['converse.queue.connection' => 'sync']);

        $prompt = $this->argument('prompt');

        if (! is_string($prompt) && ! $this->interactive()) {
            $this->components->error('Pass a prompt to preview a single message, or run this command in an interactive terminal.');

            return static::FAILURE;
        }

        $this->open($registrar->get($bot), is_string($prompt));

        if ($resumed) {
            $this->replay($bot, $connection);
        }

        if (is_string($prompt)) {
            $this->say($prompt);

            $this->deliver($registrar, $bot, $this->decode($driver, ['text' => $prompt]));

            return static::SUCCESS;
        }

        while (($event = $this->event($driver)) !== null) {
            $this->deliver($registrar, $bot, $event);
        }

        $this->identify();

        return static::SUCCESS;
    }

    protected function onlyBot(BotRegistrar $registrar): ?string
    {
        $bots = $registrar->bots();

        return $bots->count() === 1 ? (string) $bots->keys()->first() : null;
    }

    protected function interactive(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }

    protected function open(Bot $bot, bool $preview): void
    {
        if (! $preview) {
            title("converse · {$bot->name()}");
        }

        $this->screen->render(Screen::header(
            $bot->name(),
            ($bot instanceof AgentBot ? 'agent' : 'handler').' · '.$bot->connectionsWithTerminal()->implode(', '),
            $preview
                ? 'Previewing a single message.'
                : 'Type a message, /help for commands, or Esc to leave.',
            $this->screen->width(),
        ));
    }

    protected function replay(string $bot, string $connection): void
    {
        $thread = new Thread($connection, TerminalDriver::CHANNEL, $this->thread, directMessage: true)->withBot($bot);

        $this->laravel->make(ConversationStore::class)
            ->messages($thread, static::REPLAYED)
            ->reverse()
            ->each(function (Message $message) use ($bot): void {
                $message->user()->isSelf()
                    ? $this->turn($bot, 'text-cyan', Markdown::make($message->text()))
                    : $this->say($message->text());
            });
    }

    protected function listen(): void
    {
        $events = $this->laravel->make('events');

        $events->listen(ToolInvoked::class, fn (ToolInvoked $event) => $this->screen->render(Screen::tool(
            ToolNameResolver::resolve($event->tool), $event->arguments, $event->result, $event->time, false, $this->displays['tools'], $this->screen->width(),
        )));

        $events->listen(ToolFailed::class, fn (ToolFailed $event) => $this->screen->render(Screen::tool(
            ToolNameResolver::resolve($event->tool), $event->arguments, $event->exception->getMessage(), $event->time, true, $this->displays['tools'], $this->screen->width(),
        )));

        $events->listen(AgentStreamed::class, function (AgentStreamed $event): void {
            $usage = $event->response->steps->last()->usage ?? $event->response->usage;

            $this->tokens += $event->response->usage->outputTokens;
            $this->context = $usage->totalTokens();

            $this->screen->render(Screen::reasoning($event->response->reasoning, $this->displays['reasoning'], $this->screen->width()));
        });
    }

    protected function say(string $prompt): void
    {
        $this->turn(TerminalDriver::USER, '', $prompt);
    }

    protected function deliver(BotRegistrar $registrar, string $bot, Message|Action|BotCommand $event): void
    {
        $this->tokens = 0;
        $this->context = null;
        $startedAt = microtime(true);

        $this->trap(fn (): array => [SIGINT], fn () => throw new InterruptedException('The reply was stopped.'));

        try {
            $registrar->incoming($bot, $event);
        } catch (InterruptedException) {
            $this->screen->render(Screen::status('Stopped.'));
        } catch (Throwable $throwable) {
            report($throwable);

            callout('Error', $this->explain($throwable), 'error');
        } finally {
            $this->untrap();
        }

        if ($this->tokens > 0) {
            $this->screen->render(Screen::stats($this->tokens, microtime(true) - $startedAt, $this->context, $this->window));
        }
    }

    protected function explain(Throwable $throwable): string
    {
        for ($cause = $throwable; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException && in_array($cause->response->status(), [401, 403], true)) {
                $host = $cause->response->effectiveUri()?->getHost();

                return ($host === null ? 'The provider' : "[{$host}]")
                    ." rejected the request with a [{$cause->response->status()}] status. Make sure its API key is set in your [.env] file, such as [OPENAI_API_KEY] or [ANTHROPIC_API_KEY].";
            }
        }

        return $throwable->getMessage();
    }

    protected function turn(string $label, string $classes, Markdown|string $body): void
    {
        $width = $this->screen->width();

        $this->screen->render(Screen::turn($label, $classes, TerminalRenderer::render($body, $width), $width));
    }

    protected function event(TerminalDriver $driver): Message|Action|BotCommand|null
    {
        $chosen = $this->chosen($driver);

        if ($chosen instanceof TerminalAction && $chosen->id() !== null) {
            return $this->decode($driver, ['action' => ['id' => $chosen->id(), 'value' => $chosen->value()]]);
        }

        while (($text = $this->read()) !== null && ! in_array(Str::lower(Str::trim($text)), static::PARTING, true)) {
            $text = Str::trim($text);

            if (! $this->builtIn($text)) {
                $this->history[] = $text;

                return $this->decode($driver, ['text' => Str::startsWith($text, '//') ? Str::substr($text, 1) : $text]);
            }
        }

        return null;
    }

    protected function builtIn(string $text): bool
    {
        [$name, $argument] = array_pad(explode(' ', $text, 2), 2, '');

        return match (Str::lower($name)) {
            '/help' => $this->help(),
            '/new' => $this->restart(),
            '/id' => $this->identify(),
            '/clear' => $this->clear(),
            '/tools' => $this->display('tools', $argument),
            '/reasoning' => $this->display('reasoning', $argument),
            default => false,
        };
    }

    protected function help(): bool
    {
        callout('Commands', [
            Element::keyValueList(static::COMMANDS),
            '//command sends /command to the bot. Ctrl+C stops a reply.',
        ]);

        return true;
    }

    protected function clear(): bool
    {
        clear();

        return true;
    }

    protected function restart(): bool
    {
        $this->thread = (string) Str::uuid();

        $this->screen->render(Screen::status('Started a new conversation.'));

        return true;
    }

    protected function identify(): bool
    {
        $this->screen->render(Screen::status("Resume this conversation with --thread={$this->thread}"));

        return true;
    }

    /** @param  'tools'|'reasoning'  $section */
    protected function display(string $section, string $mode): bool
    {
        $display = TerminalDisplay::tryFrom(Str::lower(Str::trim($mode)));

        if (! $display instanceof TerminalDisplay) {
            $this->screen->render(Screen::status("Choose /{$section} full, collapsed, or hidden."));

            return true;
        }

        $this->displays[$section] = $display;

        $this->screen->render(Screen::status("Showing {$section} {$display->value}."));

        return true;
    }

    protected function chosen(TerminalDriver $driver): ?TerminalAction
    {
        $actions = $driver->actions();

        if ($actions->isEmpty()) {
            return null;
        }

        $driver->forgetActions();

        return $this->choose($actions);
    }

    /** @param  Collection<int, TerminalAction>  $actions */
    protected function choose(Collection $actions): ?TerminalAction
    {
        $chosen = $actions->get((int) select(
            label: 'Choose an action',
            options: $actions
                ->mapWithKeys(fn (TerminalAction $action, int $index): array => [$index => $action->label()])
                ->prepend('Keep talking', static::KEEP_TALKING)
                ->all(),
            hint: 'The bot is waiting on one of its own buttons.',
        ));

        if (! $chosen instanceof TerminalAction) {
            return null;
        }

        if ($chosen->url() !== null) {
            $this->screen->render(Screen::status("That button opens {$chosen->url()}."));

            return null;
        }

        return $chosen;
    }

    protected function read(): ?string
    {
        $prompt = new AutoCompletePrompt(
            label: 'You',
            options: fn (string $typed): array => $typed === '' ? [] : collect(array_keys(static::COMMANDS))
                ->concat(array_reverse($this->history))
                ->filter(fn (string $option): bool => Str::startsWith(Str::lower($option), Str::lower($typed)))
                ->unique()
                ->values()
                ->all(),
            placeholder: 'Ask something, or type / for commands',
            required: true,
            hint: 'Tab completes · Esc leaves · Ctrl+C stops a reply',
        );

        $left = false;

        $prompt->on('key', function (string $key) use ($prompt, &$left): void {
            if ($key === Key::ESCAPE) {
                $left = true;
                $prompt->state = 'submit';
            }
        });

        $text = (string) $prompt->prompt();

        return $left ? null : $text;
    }

    /** @param  array<string, mixed>  $payload */
    protected function decode(TerminalDriver $driver, array $payload): Message|Action|BotCommand
    {
        return collect($driver->events([...$payload, 'thread' => $this->thread]))->firstOrFail();
    }
}
