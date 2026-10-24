<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Telegram;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Converse\Attachment;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\Reaction;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Channel;
use Laravel\Converse\Contracts\Drivers\ReceivesWebhooks;
use Laravel\Converse\Contracts\Drivers\SupportsAddReactions;
use Laravel\Converse\Contracts\Drivers\SupportsDeleteMessages;
use Laravel\Converse\Contracts\Drivers\SupportsDownloadAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\Contracts\Drivers\SupportsFindChannels;
use Laravel\Converse\Contracts\Drivers\SupportsGetChannels;
use Laravel\Converse\Contracts\Drivers\SupportsOpenDirectMessages;
use Laravel\Converse\Contracts\Drivers\SupportsRemoveReactions;
use Laravel\Converse\Contracts\Drivers\SupportsSendAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsUpdateStatuses;
use Laravel\Converse\Drivers\Driver;
use Laravel\Converse\Enums\AttachmentType;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Exceptions\AttachmentException;
use Laravel\Converse\Exceptions\DriverException;
use Laravel\Converse\Exceptions\DriverRequestException;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;
use Throwable;

class TelegramDriver extends Driver implements ReceivesWebhooks, SupportsAddReactions, SupportsDeleteMessages, SupportsDownloadAttachments, SupportsEditMessages, SupportsFindChannels, SupportsGetChannels, SupportsOpenDirectMessages, SupportsRemoveReactions, SupportsSendAttachments, SupportsUpdateStatuses
{
    /** @var array<string, string> */
    protected const array MEDIA = [
        'photo' => 'image/jpeg',
        'sticker' => 'image/webp',
        'document' => Attachment::DEFAULT_MIME_TYPE,
        'audio' => Attachment::DEFAULT_MIME_TYPE,
        'voice' => 'audio/ogg',
        'video' => Attachment::DEFAULT_MIME_TYPE,
        'video_note' => 'video/mp4',
        'animation' => Attachment::DEFAULT_MIME_TYPE,
    ];

    public function verify(Request $request): bool
    {
        $secret = $this->token('webhook_secret');

        return $secret !== ''
            && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|Action|Reaction|Command>
     */
    public function events(array $payload): iterable
    {
        $eventId = isset($payload['update_id']) ? (string) $payload['update_id'] : null;

        $message = $payload['message'] ?? $payload['edited_message'] ?? $payload['channel_post'] ?? $payload['edited_channel_post'] ?? null;

        if (is_array($message)) {
            yield from $this->messageEvents($message, $eventId);
        }

        if (isset($payload['callback_query'])) {
            yield $this->callbackEvent($payload['callback_query'], $eventId);
        }

        if (isset($payload['message_reaction'])) {
            yield from $this->reactionEvents($payload['message_reaction'], $eventId);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return iterable<int, Message|Command>
     */
    protected function messageEvents(array $message, ?string $eventId): iterable
    {
        $thread = $this->thread($message);
        $user = $this->user($message['from'] ?? $message['sender_chat'] ?? $message['chat'] ?? []);
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $isDirectMessage = $this->isPrivateChat($message['chat'] ?? null);

        if ($this->isCommand($message)) {
            [$command, $arguments] = $this->parseCommand($text);

            yield new Command($thread, $command, $user, $arguments, $eventId, $message, $isDirectMessage);

            return;
        }

        yield Message::make(
            thread: $thread,
            text: $text,
            user: $user,
            sentAt: $this->timestamp($message['date']),
        )->withMention($this->isMention($text))
            ->withDirectMessage($isDirectMessage)
            ->withId($this->messageId($thread, (string) ($message['message_id'] ?? '')))
            ->withEventId($eventId)
            ->withEditedAt(isset($message['edit_date']) ? $this->timestamp($message['edit_date']) : null)
            ->withAttachments($this->attachments($message));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return Collection<int, Attachment>
     */
    protected function attachments(array $message): Collection
    {
        foreach (static::MEDIA as $key => $fallback) {
            $media = $message[$key] ?? null;

            if ($key === 'photo') {
                $media = is_array($media) ? end($media) : null;
            }

            if (! is_array($media) || ($media['file_id'] ?? '') === '') {
                continue;
            }

            $mimeType = (string) ($media['mime_type'] ?? $fallback);

            return collect([new Attachment(
                type: AttachmentType::fromMimeType($mimeType),
                mimeType: $mimeType,
                name: $media['file_name'] ?? null,
                size: isset($media['file_size']) ? (int) $media['file_size'] : null,
                id: (string) $media['file_id'],
            )]);
        }

        return collect();
    }

    public function downloadAttachment(Attachment $attachment, int $maxBytes, int $timeout): string
    {
        $fileId = (string) $attachment->id();

        try {
            $path = (string) ($this->api('getFile', ['file_id' => $fileId])['file_path'] ?? '');

            if ($path === '' || Str::startsWith($path, '/') || Str::contains($path, ['..', '\\'])) {
                throw new AttachmentException("Telegram returned an unusable path for the attachment [{$fileId}].");
            }

            return $this->downloader()->fetch($this->fileUrl($path), [], $maxBytes, $timeout);
        } catch (AttachmentException $attachmentException) {
            throw $this->scrubbed($attachmentException);
        } catch (Throwable) {
            throw new AttachmentException("Unable to download the Telegram attachment [{$fileId}].");
        }
    }

    public function sendAttachment(Thread $thread, Attachment $attachment, Card|Markdown|string|null $caption = null): SentMessage
    {
        $field = match ($attachment->type()) {
            AttachmentType::Image => 'photo',
            AttachmentType::Audio => 'audio',
            AttachmentType::Video => 'video',
            AttachmentType::Document => 'document',
        };

        $params = [
            ...$this->target($thread),
            ...Arr::whereNotNull(['caption' => $caption === null ? null : $this->caption($caption)]),
        ];

        $result = $this->isRemote($attachment)
            ? $this->api('send'.Str::studly($field), [...$params, $field => (string) $attachment->url()])
            : $this->apiWithFile('send'.Str::studly($field), $params, $field, $attachment);

        return new SentMessage($thread, (string) ($result['message_id'] ?? ''), $result);
    }

    protected function isRemote(Attachment $attachment): bool
    {
        return ! $attachment->hasContents()
            && ! $attachment->isStored()
            && Str::startsWith((string) $attachment->url(), ['http://', 'https://']);
    }

    protected function caption(Card|Markdown|string $caption): string
    {
        return $caption instanceof Card ? $caption->fallbackText() : (string) $caption;
    }

    protected function fileUrl(string $path): string
    {
        $token = $this->token('bot_token');
        $encoded = collect(explode('/', $path))->map(rawurlencode(...))->implode('/');

        return "https://api.telegram.org/file/bot{$token}/{$encoded}";
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function apiWithFile(string $method, array $params, string $field, Attachment $attachment): array
    {
        $token = $this->token('bot_token');

        try {
            $response = Http::timeout((int) config('converse.attachments.timeout'))
                ->setHandler($this->handler())
                ->attach($field, $attachment->contents(), $attachment->label())
                ->post("https://api.telegram.org/bot{$token}/{$method}", $params)
                ->throw();
        } catch (RequestException $requestException) {
            throw $this->rejected($method, $requestException);
        } catch (ConnectionException $exception) {
            throw $this->scrubbed($exception);
        }

        return (array) $response->json('result', []);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function callbackEvent(array $query, ?string $eventId): Action
    {
        try {
            $this->api('answerCallbackQuery', ['callback_query_id' => (string) ($query['id'] ?? '')]);
        } catch (DriverException|HttpClientException $exception) {
            report($exception);
        }

        $data = (string) ($query['data'] ?? '');
        $segments = explode('|', $data, 2);

        return new Action(
            thread: $this->thread($query['message'] ?? []),
            actionId: $segments[0] === '' ? 'telegram_callback' : $segments[0],
            user: $this->user($query['from'] ?? []),
            value: $segments[1] ?? null,
            messageId: isset($query['message']['message_id']) ? (string) $query['message']['message_id'] : null,
            eventId: $eventId,
            raw: $query,
            isDirectMessage: $this->isPrivateChat($query['message']['chat'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $update
     * @return iterable<int, Reaction>
     */
    protected function reactionEvents(array $update, ?string $eventId): iterable
    {
        $old = $this->reactionEmojis($update['old_reaction'] ?? []);
        $new = $this->reactionEmojis($update['new_reaction'] ?? []);

        foreach (array_diff($new, $old) as $emoji) {
            yield $this->reactionEvent($update, $emoji, true, $eventId === null ? null : $eventId.':added:'.$emoji);
        }

        foreach (array_diff($old, $new) as $emoji) {
            yield $this->reactionEvent($update, $emoji, false, $eventId === null ? null : $eventId.':removed:'.$emoji);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function reactionEmojis(mixed $reactions): array
    {
        return collect((array) $reactions)
            ->map(fn (mixed $reaction): string => match (data_get($reaction, 'type')) {
                'emoji' => (string) data_get($reaction, 'emoji', ''),
                'custom_emoji' => 'custom_emoji:'.data_get($reaction, 'custom_emoji_id', ''),
                default => '',
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $update
     */
    protected function reactionEvent(array $update, string $emoji, bool $added, ?string $eventId): Reaction
    {
        return new Reaction(
            thread: $this->thread($update),
            emoji: $emoji,
            user: $this->user($update['user'] ?? $update['actor_chat'] ?? []),
            messageId: isset($update['message_id']) ? (string) $update['message_id'] : null,
            added: $added,
            eventId: $eventId,
            raw: $update,
            isDirectMessage: $this->isPrivateChat($update['chat'] ?? null),
        );
    }

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        return $this->forThread($thread, function () use ($thread, $content): SentMessage {
            $result = $this->isRichCard($content)
                ? $this->sendRichCard('sendRichMessage', 'sendMessage', $content, $this->target($thread))
                : $this->apiWithMarkdown(
                    'sendMessage',
                    [...$this->params($content), ...$this->target($thread)],
                    $content instanceof Markdown ? $content->content() : null,
                );

            return new SentMessage($thread, (string) ($result['message_id'] ?? ''), $result);
        });
    }

    /**
     * @phpstan-assert-if-true Card $content
     */
    protected function isRichCard(Card|Markdown|string $content): bool
    {
        return $content instanceof Card && ! array_key_exists('telegram', $content->raw());
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    protected function sendRichCard(string $method, string $fallbackMethod, Card $card, array $target): array
    {
        try {
            return $this->api($method, [...RichMessageRenderer::render($card), ...$target]);
        } catch (DriverRequestException $driverRequestException) {
            if (! $this->unsupportedRichMessage($driverRequestException)) {
                throw $driverRequestException;
            }

            return $this->apiWithMarkdown($fallbackMethod, [...KeyboardRenderer::render($card), ...$target]);
        }
    }

    protected function unsupportedRichMessage(DriverRequestException $requestException): bool
    {
        return Str::contains(
            Str::lower((string) $requestException->response()->json('description')),
            ['method not found', 'unsupported', 'rich_message', 'parse entities', "can't find end"],
        );
    }

    public function openDirectMessage(string $recipient): Thread
    {
        return new Thread($this->connection(), $recipient, directMessage: true);
    }

    public function editMessage(Thread $thread, string $messageId, Card|Markdown|string $content): SentMessage
    {
        return $this->forThread($thread, function () use ($thread, $messageId, $content): SentMessage {
            $target = ['chat_id' => $thread->channel()->id(), 'message_id' => $this->messageTarget($messageId)];

            $result = $this->isRichCard($content)
                ? $this->sendRichCard('editMessageText', 'editMessageText', $content, $target)
                : $this->apiWithMarkdown('editMessageText', [...$this->params($content), ...$target], $content instanceof Markdown ? $content->content() : null);

            return new SentMessage($thread, $messageId, $result);
        });
    }

    public function deleteMessage(Thread $thread, string $messageId): void
    {
        $this->api('deleteMessage', [
            'chat_id' => $thread->channel()->id(),
            'message_id' => $this->messageTarget($messageId),
        ]);
    }

    public function addReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->api('setMessageReaction', [
            'chat_id' => $thread->channel()->id(),
            'message_id' => $this->messageTarget($messageId),
            'reaction' => [['type' => 'emoji', 'emoji' => Emoji::unicode($emoji)]],
        ]);
    }

    public function removeReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->api('setMessageReaction', [
            'chat_id' => $thread->channel()->id(),
            'message_id' => $this->messageTarget($messageId),
            'reaction' => [],
        ]);
    }

    public function updateStatus(Thread $thread, ThreadStatus|string|null $status = null): void
    {
        if ($status === ThreadStatus::Idle) {
            return;
        }

        $this->api('sendChatAction', [...$this->target($thread), 'action' => 'typing']);
    }

    public function getChannel(string $id): Channel
    {
        $chat = $this->api('getChat', ['chat_id' => $id]);
        $private = $this->isPrivateChat($chat);

        return $this->channel($chat, $id, $private ? null : $this->memberCount($id));
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    protected function channel(array $chat, string $id, ?int $memberCount, ?Closure $resolver = null): Channel
    {
        $name = $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? null;

        return new Channel(
            id: (string) ($chat['id'] ?? $id),
            name: $name === null ? null : (string) $name,
            isDirectMessage: $this->isPrivateChat($chat),
            memberCount: $memberCount,
            resolver: $resolver,
        );
    }

    protected function memberCount(string $id): ?int
    {
        $result = array_values($this->api('getChatMemberCount', ['chat_id' => $id]));

        return $result === [] ? null : (int) $result[0];
    }

    public function findChannel(string $name): ?Channel
    {
        try {
            $chat = $this->api('getChat', ['chat_id' => '@'.ltrim($name, '#@')]);
        } catch (DriverRequestException $driverRequestException) {
            if (! Str::contains(Str::lower((string) $driverRequestException->response()->json('description')), ['not found', 'invalid'])) {
                throw $driverRequestException;
            }

            return null;
        }

        $id = $chat['id'] ?? null;

        return $id === null ? null : $this->channel($chat, (string) $id, null, fn (): Channel => $this->getChannel((string) $id));
    }

    public function mention(User|string $user): Markdown
    {
        $username = $user instanceof User ? $user->username() : null;

        if ($username !== null) {
            return Markdown::make('@'.Str::ltrim($username, '@'));
        }

        $id = $this->userId($user);
        $label = $user instanceof User ? $user->name() ?? $id : $id;

        return Markdown::make('['.Str::remove(['[', ']'], $label)."](tg://user?id={$id})");
    }

    /**
     * @return array<string, mixed>
     */
    protected function target(Thread $thread): array
    {
        return Arr::whereNotNull([
            'chat_id' => $thread->channel()->id(),
            'message_thread_id' => $thread->id() === null ? null : (int) $thread->id(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function params(Card|Markdown|string $content): array
    {
        $params = match (true) {
            $content instanceof Card => KeyboardRenderer::render($content),
            $content instanceof Markdown => ['text' => MarkdownRenderer::render($content->content()), 'parse_mode' => 'MarkdownV2'],
            default => ['text' => $content],
        };
        unset($params['chat_id'], $params['message_thread_id'], $params['message_id']);

        return $params;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function thread(array $message): Thread
    {
        return new Thread(
            $this->connection(),
            (string) ($message['chat']['id'] ?? ''),
            isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function user(array $data): User
    {
        return new User(
            id: (string) ($data['id'] ?? ''),
            name: Str::trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: ($data['title'] ?? null),
            username: $data['username'] ?? null,
            isBot: (bool) ($data['is_bot'] ?? false),
            isSelf: $this->isSelf($data),
        );
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function isSelf(array $user): bool
    {
        $username = Str::lower($this->botUsername());

        return $username !== ''
            && (bool) ($user['is_bot'] ?? false)
            && Str::lower(Str::ltrim((string) ($user['username'] ?? ''), '@')) === $username;
    }

    protected function botUsername(): string
    {
        return Str::ltrim((string) ($this->config['bot_username'] ?? ''), '@');
    }

    protected function messageId(Thread $thread, string $messageId): string
    {
        return $thread->channel()->id().':'.$messageId;
    }

    protected function messageTarget(string $messageId): int
    {
        return (int) Str::afterLast($messageId, ':');
    }

    protected function timestamp(mixed $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function isCommand(array $message): bool
    {
        $entities = isset($message['text']) ? $message['entities'] ?? [] : $message['caption_entities'] ?? [];
        $hasLeadingCommand = array_any(
            (array) $entities,
            fn (array $entity): bool => ($entity['type'] ?? null) === 'bot_command'
                && ($entity['offset'] ?? null) === 0,
        );

        if (! $hasLeadingCommand) {
            return false;
        }

        [$command] = $this->splitCommand((string) ($message['text'] ?? $message['caption'] ?? ''));

        $target = Str::after($command, '@');

        if (! Str::contains($command, '@') || $target === '') {
            return true;
        }

        $username = Str::lower($this->botUsername());

        return $username !== '' && Str::lower($target) === $username;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseCommand(string $text): array
    {
        [$command, $arguments] = $this->splitCommand($text);

        return [Str::ltrim(Str::before($command, '@'), '/'), Str::trim($arguments)];
    }

    protected function isPrivateChat(mixed $chat): bool
    {
        return data_get($chat, 'type') === 'private';
    }

    protected function isMention(string $text): bool
    {
        $username = $this->botUsername();

        return $username !== '' && preg_match('/@'.preg_quote($username, '/').'(?![a-z0-9_-])/iu', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function apiWithMarkdown(string $method, array $params, ?string $plainText = null): array
    {
        try {
            return $this->api($method, $params);
        } catch (DriverRequestException $driverRequestException) {
            if (! isset($params['parse_mode']) || ! Str::contains(Str::lower((string) $driverRequestException->response()->json('description')), ['parse entities', "can't find end"])) {
                throw $driverRequestException;
            }

            if ($params['parse_mode'] === 'HTML') {
                $params['text'] = html_entity_decode(strip_tags((string) ($params['text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif ($plainText !== null) {
                $params['text'] = $plainText;
            }

            unset($params['parse_mode']);

            return $this->api($method, $params);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function api(string $method, array $params): array
    {
        $token = $this->token('bot_token');

        try {
            $response = Http::timeout(static::API_TIMEOUT)
                ->setHandler($this->handler())
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/{$method}", $params)
                ->throw();
        } catch (RequestException $requestException) {
            throw $this->rejected($method, $requestException);
        } catch (ConnectionException $exception) {
            throw $this->scrubbed($exception);
        }

        return (array) $response->json('result', []);
    }

    protected function rejected(string $method, RequestException $requestException): DriverRequestException
    {
        return new DriverRequestException($this->scrub(sprintf(
            'Telegram API call [%s] failed with status [%d] and error [%s].',
            $method,
            $requestException->response->status(),
            (string) $requestException->response->json('description', 'unknown error'),
        )), $requestException->response);
    }

    protected function scrubbed(Throwable $throwable): Throwable
    {
        $message = $this->scrub($throwable->getMessage());

        return match (true) {
            $message === $throwable->getMessage() => $throwable,
            $throwable instanceof ConnectionException => new ConnectionException($message),
            default => new DriverException($message),
        };
    }

    protected function scrub(string $message): string
    {
        $token = $this->token('bot_token');

        return $token === '' ? $message : str_replace($token, '[redacted]', $message);
    }
}
