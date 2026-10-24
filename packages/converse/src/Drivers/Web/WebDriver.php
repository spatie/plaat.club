<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Ai\AgentUserInteraction\Chat;
use Laravel\Ai\Models\Conversation;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\Drivers\SuppliesAgentHistory;
use Laravel\Converse\Contracts\Drivers\TreatsDirectMessagesAsMentions;
use Laravel\Converse\Drivers\Driver;
use Laravel\Converse\Exceptions\DriverException;
use Laravel\Converse\Markdown;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class WebDriver extends Driver implements SuppliesAgentHistory, TreatsDirectMessagesAsMentions
{
    public const string ID_PATTERN = '[A-Za-z0-9_-]{1,64}';

    protected const string SESSION_KEY = 'converse.session';

    /**
     * @internal
     *
     * @return array<string, array<string, mixed>>
     */
    public static function connections(): array
    {
        return collect((array) config('converse.connections'))
            ->filter(fn (mixed $config): bool => is_array($config) && ($config['driver'] ?? null) === 'web')
            ->map(fn (array $config, string $name): array => ['name' => $name] + $config)
            ->all();
    }

    /** @internal */
    public static function isWebConnection(string $name): bool
    {
        return array_key_exists($name, static::connections());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message>
     */
    public function events(array $payload): iterable
    {
        return [];
    }

    /** @internal */
    public function options(): WebChatOptions
    {
        return WebChatOptions::fromConfig($this->config);
    }

    /** @internal */
    public function url(Bot $bot): string
    {
        return route("converse.{$this->connection()}.send", ['bot' => $bot->name()], false);
    }

    /** @internal */
    public function streamUrl(Bot $bot): string
    {
        return route("converse.{$this->connection()}.stream", ['bot' => $bot->name(), 'thread' => ':thread'], false);
    }

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        $events = Container::getInstance()->make(RunWriters::class)->for($thread)
            ?? throw new DriverException("Connection [{$this->connection()}] only answers inside a browser turn.");

        $messageId = (string) Str::uuid7();

        foreach (TurnEvents::message($messageId, 'assistant', $this->text($content)) as $event) {
            $events->write($event);
        }

        return new SentMessage($thread, $messageId);
    }

    /** @internal */
    public function event(Bot $bot, Thread $thread, Chat $input, ?object $user, ?string $messageId = null): Message|Command
    {
        $text = (string) $input->message()?->content;

        if (! $this->isCommand($text) || $bot->handlerFor(Command::class) === null) {
            return $this->message($thread, $text, $user, $messageId);
        }

        [$command, $arguments] = $this->splitCommand($text);

        return new Command(
            thread: $thread,
            name: Str::ltrim($command, '/'),
            user: $this->user($user),
            text: Str::trim($arguments),
            eventId: $messageId,
            isDirectMessage: true,
        );
    }

    /** @internal */
    public function owner(Request $request, ?object $user, Bot $bot): ?string
    {
        if ($user !== null) {
            return Conversation::participantType($user).':'.Conversation::participantKey($user);
        }

        if (! $this->allowsGuests($bot) || ! $request->hasSession()) {
            return null;
        }

        return 'session:'.$this->visitorId($request->session());
    }

    protected function allowsGuests(Bot $bot): bool
    {
        return $bot->allowsWebGuests() ?? $this->options()->allowsGuests();
    }

    /** @internal */
    public function thread(string $owner, ?string $conversation, string $bot): Thread
    {
        return new Thread($this->connection(), $owner, $conversation, directMessage: true, bot: $bot);
    }

    protected function visitorId(Session $session): string
    {
        $id = $session->get(static::SESSION_KEY);

        if (! is_string($id)) {
            $session->put(static::SESSION_KEY, $id = (string) Str::uuid7());
        }

        return $id;
    }

    protected function message(Thread $thread, string $text, ?object $user, ?string $messageId): Message
    {
        return Message::make(
            thread: $thread,
            text: $text,
            user: $this->user($user),
            sentAt: Date::now()->toDateTimeImmutable(),
        )->withDirectMessage()
            ->withId($messageId);
    }

    protected function user(?object $user): User
    {
        if ($user === null) {
            return User::anonymous();
        }

        if (method_exists($user, 'toConverseUser')) {
            return $user->toConverseUser();
        }

        $id = ($user instanceof Authenticatable ? $user->getAuthIdentifier() : null) ?? Conversation::participantKey($user);

        return new User(id: (string) $id, name: $this->userName($user));
    }

    protected function userName(object $user): ?string
    {
        $name = data_get($user, 'name');

        return is_string($name) ? $name : null;
    }

    protected function isCommand(string $text): bool
    {
        return Str::startsWith($text, '/') && strlen($this->splitCommand($text)[0]) > 1;
    }

    protected function text(Card|Markdown|string $content): string
    {
        return match (true) {
            $content instanceof Card => $content->fallbackText(),
            $content instanceof Markdown => $content->content(),
            default => $content,
        };
    }
}
