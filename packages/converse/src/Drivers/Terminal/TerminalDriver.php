<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\Drivers\SupportsUpdateStatuses;
use Laravel\Converse\Contracts\Drivers\TreatsDirectMessagesAsMentions;
use Laravel\Converse\Drivers\Driver;
use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Markdown;
use Laravel\Converse\Routing\CallbackRegistry;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class TerminalDriver extends Driver implements SupportsUpdateStatuses, TreatsDirectMessagesAsMentions
{
    public const string CONNECTION = 'terminal';

    public const string CHANNEL = 'terminal';

    public const string USER = 'you';

    /** @var Collection<int, TerminalAction> */
    protected Collection $actions;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->actions = collect();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|Action|Command>
     */
    public function events(array $payload): iterable
    {
        $thread = new Thread(
            $this->connection(),
            (string) data_get($payload, 'channel', static::CHANNEL),
            data_get($payload, 'thread') === null ? null : (string) data_get($payload, 'thread'),
            directMessage: true,
        );
        $user = new User((string) data_get($payload, 'user', static::USER));

        if (data_get($payload, 'action.id') !== null) {
            yield new Action(
                thread: $thread,
                actionId: (string) data_get($payload, 'action.id'),
                user: $user,
                value: data_get($payload, 'action.value') === null ? null : (string) data_get($payload, 'action.value'),
                isDirectMessage: true,
            );

            return;
        }

        $text = (string) data_get($payload, 'text', '');

        [$command, $arguments] = $this->command($text);

        if ($command !== '') {
            yield new Command($thread, $command, $user, $arguments, isDirectMessage: true);

            return;
        }

        yield Message::make(
            thread: $thread,
            text: $text,
            user: $user,
            sentAt: Date::now()->toDateTimeImmutable(),
        )->withDirectMessage()
            ->withId((string) data_get($payload, 'id', (string) Str::uuid()));
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function command(string $text): array
    {
        if (! Str::startsWith($text, '/')) {
            return ['', ''];
        }

        [$command, $arguments] = $this->splitCommand($text);

        return [Str::ltrim($command, '/'), Str::trim($arguments)];
    }

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        return $this->forThread($thread, function () use ($thread, $content): SentMessage {
            $content = $content instanceof Card ? $this->register($content) : $content;

            $this->actions = $content instanceof Card ? TerminalRenderer::actions($content) : collect();

            $this->write($thread->bot() ?? $this->connection(), $content);

            return new SentMessage($thread, (string) Str::uuid());
        });
    }

    public function updateStatus(Thread $thread, ThreadStatus|string|null $status = null): void
    {
        $label = $status instanceof ThreadStatus ? $status->value : $status ?? ThreadStatus::Thinking->value;

        if ($label === '') {
            return;
        }

        $this->output()->render(Screen::status($label));
    }

    /** @return Collection<int, TerminalAction> */
    public function actions(): Collection
    {
        return $this->actions->collect();
    }

    public function forgetActions(): void
    {
        $this->actions = collect();
    }

    protected function register(Card $card): Card
    {
        return Container::getInstance()->make(CallbackRegistry::class)->prepare($card);
    }

    protected function write(string $label, Card|Markdown|string $content): void
    {
        $output = $this->output();

        $output->render(Screen::turn($label, 'text-cyan', TerminalRenderer::render($content, $output->width()), $output->width()));
    }

    protected function output(): TerminalOutput
    {
        return Container::getInstance()->make(TerminalOutput::class);
    }
}
