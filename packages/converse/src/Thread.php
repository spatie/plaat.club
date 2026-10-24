<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonSerializable;
use Laravel\Converse\Actions\AddReactionAction;
use Laravel\Converse\Actions\DeleteMessageAction;
use Laravel\Converse\Actions\EditMessageAction;
use Laravel\Converse\Actions\GetChannelAction;
use Laravel\Converse\Actions\GetUserAction;
use Laravel\Converse\Actions\ListAttachmentsAction;
use Laravel\Converse\Actions\ListMessagesAction;
use Laravel\Converse\Actions\ListUsersAction;
use Laravel\Converse\Actions\PromptAction;
use Laravel\Converse\Actions\RemoveReactionAction;
use Laravel\Converse\Actions\RequestApprovalAction;
use Laravel\Converse\Actions\ResolveApprovalAction;
use Laravel\Converse\Actions\SendAttachmentAction;
use Laravel\Converse\Actions\SendEphemeralMessageAction;
use Laravel\Converse\Actions\SendMessageAction;
use Laravel\Converse\Actions\SubscribeAction;
use Laravel\Converse\Actions\UnsubscribeAction;
use Laravel\Converse\Actions\UpdateStatusAction;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Drivers\Driver;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Exceptions\InvalidIdentifierException;
use Stringable;

/** @implements Arrayable<string, mixed> */
class Thread implements Arrayable, JsonSerializable, Stringable
{
    public function __construct(
        protected string $connection,
        protected string $channel,
        protected ?string $id = null,
        protected ?User $user = null,
        protected bool $directMessage = false,
        protected ?string $bot = null,
    ) {}

    public function connection(): string
    {
        return $this->connection;
    }

    public function channel(): Channel
    {
        return once(fn (): Channel => new Channel(
            id: $this->channel,
            isDirectMessage: $this->directMessage ? true : null,
            resolver: fn (): ?Channel => $this->supports(Capability::GetChannel)
                ? Container::getInstance()->make(GetChannelAction::class)->handle($this)
                : null,
        ));
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function isDirectMessage(): bool
    {
        return $this->directMessage;
    }

    public function bot(): ?string
    {
        return $this->bot;
    }

    public function encode(): string
    {
        return $this->connection.':'.$this->externalIdPrefix().rawurlencode($this->id ?? '');
    }

    public static function decode(string $encoded, ?User $user = null, bool $directMessage = false, ?string $bot = null): static
    {
        $segments = explode(':', $encoded, 3);

        if (count($segments) !== 3) {
            throw new InvalidIdentifierException("Invalid thread ID [{$encoded}].");
        }

        $thread = rawurldecode($segments[2]);

        return new static($segments[0], rawurldecode($segments[1]), $thread === '' ? null : $thread, $user, $directMessage, $bot);
    }

    public function externalId(): string
    {
        return $this->id === null
            ? rawurlencode($this->channel)
            : $this->externalIdPrefix().rawurlencode($this->id);
    }

    public function externalIdPrefix(): string
    {
        return rawurlencode($this->channel).':';
    }

    public static function fromExternalId(string $connection, string $externalId, ?User $user = null, bool $directMessage = false, ?string $bot = null): static
    {
        [$channel, $thread] = array_pad(explode(':', $externalId, 2), 2, '');

        return new static($connection, rawurldecode($channel), $thread === '' ? null : rawurldecode($thread), $user, $directMessage, $bot);
    }

    public function forChannel(): static
    {
        return $this->withId(null);
    }

    public function withId(?string $id): static
    {
        $copy = clone $this;
        $copy->id = $id;

        return $copy;
    }

    public function withUser(User|string|null $user): static
    {
        $copy = clone $this;
        $copy->user = is_string($user) ? new User($user) : $user;

        return $copy;
    }

    public function withBot(?string $bot): static
    {
        $copy = clone $this;
        $copy->bot = $bot;

        return $copy;
    }

    public function withDirectMessage(bool $directMessage = true): static
    {
        $copy = clone $this;
        $copy->directMessage = $directMessage;

        return $copy;
    }

    public function driver(): Driver
    {
        return Container::getInstance()->make(BotRegistrar::class)->connection($this->connection, $this->bot);
    }

    public function platform(): string
    {
        return $this->driver()->platform();
    }

    public function supports(Capability $capability): bool
    {
        return $capability->supportedBy($this->driver());
    }

    public function send(Card|Markdown|string $content): SentMessage
    {
        return Container::getInstance()->make(SendMessageAction::class)->handle($this, $content);
    }

    public function prompt(string $prompt, ?string $key = null): void
    {
        Container::getInstance()->make(PromptAction::class)->handle($this, $prompt, $key);
    }

    public function sendAttachment(Attachment $attachment, Card|Markdown|string|null $caption = null): SentMessage
    {
        return Container::getInstance()->make(SendAttachmentAction::class)->handle($this, $attachment, $caption);
    }

    public function sendEphemeral(Card|Markdown|string $content, User|string|null $to = null): SentMessage
    {
        $thread = $to === null ? $this : $this->withUser($to);

        return Container::getInstance()->make(SendEphemeralMessageAction::class)->handle($thread, $content);
    }

    public function requestApproval(Approval $approval): SentMessage
    {
        return Container::getInstance()->make(RequestApprovalAction::class)->handle($this, $approval);
    }

    /**
     * @param  array<string, bool>  $decisions
     */
    public function resolveApproval(Approval $approval, SentMessage $request, array $decisions): SentMessage
    {
        return Container::getInstance()->make(ResolveApprovalAction::class)->handle($this, $approval, $request, $decisions);
    }

    public function hasSent(string $messageId): bool
    {
        return $this->conversations()->hasSent($this, $messageId);
    }

    public function edit(string $messageId, Card|Markdown|string $content): SentMessage
    {
        return Container::getInstance()->make(EditMessageAction::class)->handle($this, $messageId, $content);
    }

    public function delete(string $messageId): void
    {
        Container::getInstance()->make(DeleteMessageAction::class)->handle($this, $messageId);
    }

    public function addReaction(string $messageId, Emoji|string $emoji): void
    {
        Container::getInstance()->make(AddReactionAction::class)->handle($this, $messageId, $emoji);
    }

    public function removeReaction(string $messageId, Emoji|string $emoji): void
    {
        Container::getInstance()->make(RemoveReactionAction::class)->handle($this, $messageId, $emoji);
    }

    public function subscribe(): void
    {
        Container::getInstance()->make(SubscribeAction::class)->handle($this);
    }

    public function unsubscribe(): void
    {
        Container::getInstance()->make(UnsubscribeAction::class)->handle($this);
    }

    public function isSubscribed(): bool
    {
        return $this->conversations()->isSubscribed($this);
    }

    public function wasStartedByBot(): bool
    {
        return $this->conversations()->wasStartedByBot($this);
    }

    public function meta(?string $name = null, mixed $default = null): mixed
    {
        return Arr::get($this->conversations()->meta($this), $name, $default);
    }

    public function putMeta(string $name, mixed $value): void
    {
        $this->conversations()->putMeta($this, $name, $value);
    }

    public function forgetMeta(string $name): void
    {
        $this->conversations()->forgetMeta($this, $name);
    }

    public function updateStatus(ThreadStatus|string|null $status = null): void
    {
        Container::getInstance()->make(UpdateStatusAction::class)->handle($this, $status);
    }

    public function clearStatus(): void
    {
        Container::getInstance()->make(UpdateStatusAction::class)->handle($this, ThreadStatus::Idle);
    }

    /**
     * @return iterable<int, Message>
     */
    public function messages(?int $limit = null, ?string $cursor = null): iterable
    {
        return Container::getInstance()->make(ListMessagesAction::class)->handle($this, $limit, $cursor);
    }

    /**
     * @return Collection<int, Attachment>
     */
    public function attachments(?int $limit = null): Collection
    {
        return Container::getInstance()->make(ListAttachmentsAction::class)->handle($this, $limit);
    }

    /**
     * @return Collection<int, User>
     */
    public function users(): Collection
    {
        return Container::getInstance()->make(ListUsersAction::class)->handle($this);
    }

    public function mention(User|string $user): Markdown
    {
        return $this->driver()->mention($user);
    }

    public function getUser(User|string $user): ?User
    {
        return Container::getInstance()->make(GetUserAction::class)->handle($this, $user);
    }

    public function __toString(): string
    {
        return $this->encode();
    }

    /**
     * @return array{connection: string, channel: string, id: string|null, user: array<string, mixed>|null, direct_message: bool, bot: string|null}
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'channel' => $this->channel,
            'id' => $this->id,
            'user' => $this->user?->toArray(),
            'direct_message' => $this->directMessage,
            'bot' => $this->bot,
        ];
    }

    /**
     * @return array{connection: string, channel: string, id: string|null, user: array<string, mixed>|null, direct_message: bool, bot: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected function conversations(): ConversationStore
    {
        return Container::getInstance()->make(ConversationStore::class);
    }
}
