<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Slack;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Laravel\Converse\Approval;
use Laravel\Converse\Attachment;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\MessageDeleted;
use Laravel\Converse\BotEvents\MessageUpdated;
use Laravel\Converse\BotEvents\Reaction;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Channel;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\Drivers\IssuesWebhookSecrets;
use Laravel\Converse\Contracts\Drivers\ReceivesWebhooks;
use Laravel\Converse\Contracts\Drivers\SupportsAddReactions;
use Laravel\Converse\Contracts\Drivers\SupportsCreateInlineResponses;
use Laravel\Converse\Contracts\Drivers\SupportsDeleteMessages;
use Laravel\Converse\Contracts\Drivers\SupportsDownloadAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\Contracts\Drivers\SupportsFindChannels;
use Laravel\Converse\Contracts\Drivers\SupportsFindUsers;
use Laravel\Converse\Contracts\Drivers\SupportsGetChannels;
use Laravel\Converse\Contracts\Drivers\SupportsGetUsers;
use Laravel\Converse\Contracts\Drivers\SupportsListMessages;
use Laravel\Converse\Contracts\Drivers\SupportsOpenDirectMessages;
use Laravel\Converse\Contracts\Drivers\SupportsRemoveReactions;
use Laravel\Converse\Contracts\Drivers\SupportsSendAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsSendEphemeralMessages;
use Laravel\Converse\Contracts\Drivers\SupportsUpdateStatuses;
use Laravel\Converse\Drivers\Driver;
use Laravel\Converse\Enums\AttachmentType;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Exceptions\AttachmentException;
use Laravel\Converse\Exceptions\ConverseException;
use Laravel\Converse\Exceptions\DriverException;
use Laravel\Converse\Exceptions\InvalidCardException;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;
use Symfony\Component\HttpFoundation\Response;

class SlackDriver extends Driver implements IssuesWebhookSecrets, ReceivesWebhooks, SupportsAddReactions, SupportsCreateInlineResponses, SupportsDeleteMessages, SupportsDownloadAttachments, SupportsEditMessages, SupportsFindChannels, SupportsFindUsers, SupportsGetChannels, SupportsGetUsers, SupportsListMessages, SupportsOpenDirectMessages, SupportsRemoveReactions, SupportsSendAttachments, SupportsSendEphemeralMessages, SupportsUpdateStatuses
{
    /** @var array<int, string> */
    protected const array IGNORED_SUBTYPES = [
        'message_replied',
        'tombstone',
        'ekm_access_denied',
        'channel_join',
        'channel_leave',
        'channel_topic',
        'channel_purpose',
        'channel_name',
        'channel_archive',
        'channel_unarchive',
        'group_join',
        'group_leave',
        'group_topic',
        'group_purpose',
        'group_name',
        'group_archive',
        'group_unarchive',
    ];

    /** @var array<int, string> */
    protected const array FILE_HOSTS = ['files.slack.com', 'enterprise.slack.com', '*.enterprise.slack.com'];

    /** @var array<int, string> */
    protected const array UPLOAD_HOSTS = ['files.slack.com', '*.slack.com'];

    /** @var array<int, string> */
    protected const array IGNORED_FILE_MODES = ['tombstone', 'hidden_by_limit'];

    protected const int PER_PAGE = 200;

    protected string $botId = '';

    protected string $botUserId = '';

    protected string $appId = '';

    protected bool $botIdentityResolved = false;

    public function verify(Request $request): bool
    {
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp');
        $secret = $this->token('webhook_secret');

        if ($secret === '' || $timestamp === '' || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signature = 'v0='.hash_hmac(
            'sha256',
            "v0:{$timestamp}:{$request->getContent()}",
            $secret,
        );

        return hash_equals($signature, (string) $request->header('X-Slack-Signature'));
    }

    public function webhookSecretHint(): string
    {
        return 'Slack issues its own signing secret. Copy it from your Slack application under Basic Information.';
    }

    public function createInlineResponse(Request $request): ?Response
    {
        $body = $request->isJson() ? $request->json() : $request->request;

        if ($body->get('type') === 'url_verification') {
            return response()->json(['challenge' => $body->get('challenge')]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, BotEvent>
     */
    public function events(array $payload): iterable
    {
        if (isset($payload['payload']) && is_string($payload['payload'])) {
            $payload = (array) json_decode($payload['payload'], true);
        }

        if (isset($payload['command'])) {
            yield $this->commandEvent($payload);

            return;
        }

        if (($payload['type'] ?? null) === 'block_actions') {
            yield from $this->actionEvents($payload);

            return;
        }

        if (($payload['type'] ?? null) === 'event_callback') {
            yield from $this->callbackEvents($payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|MessageUpdated|MessageDeleted|Reaction>
     */
    protected function callbackEvents(array $payload): iterable
    {
        $event = (array) ($payload['event'] ?? []);
        $botUserId = (string) data_get($payload, 'authorizations.0.user_id', '');
        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;
        $appId = (string) ($payload['api_app_id'] ?? '');

        if ($botUserId !== '') {
            $this->botUserId = $botUserId;
        }

        if ($appId !== '') {
            $this->appId = $appId;
        }

        yield from match ($event['type'] ?? null) {
            'app_mention' => [$this->messageEvent($event, $botUserId, $eventId, isMention: true)],
            'message' => $this->plainMessageEvents($event, $botUserId, $eventId),
            'reaction_added' => [$this->reactionEvent($event, $botUserId)],
            'reaction_removed' => [$this->reactionEvent($event, $botUserId, added: false)],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $event
     * @return iterable<int, Message|MessageUpdated|MessageDeleted>
     */
    protected function plainMessageEvents(array $event, string $botUserId, ?string $eventId): iterable
    {
        if (($event['subtype'] ?? null) === 'message_changed') {
            $message = (array) ($event['message'] ?? []);

            if (($event['channel'] ?? '') !== '' && ($message['ts'] ?? '') !== '') {
                yield ($message['subtype'] ?? null) === 'tombstone'
                    ? $this->messageDeletedEvent($event, $botUserId, $eventId)
                    : $this->messageUpdatedEvent($event, $botUserId, $eventId);
            }

            return;
        }

        if (($event['subtype'] ?? null) === 'message_deleted') {
            $previous = (array) ($event['previous_message'] ?? []);

            if (($event['channel'] ?? '') !== '' && (($event['deleted_ts'] ?? $previous['ts'] ?? '') !== '')) {
                yield $this->messageDeletedEvent($event, $botUserId, $eventId);
            }

            return;
        }

        if (($event['hidden'] ?? false) === true || in_array($event['subtype'] ?? null, static::IGNORED_SUBTYPES, true)) {
            return;
        }

        yield $this->messageEvent(
            $event,
            $botUserId,
            $eventId,
            isMention: $botUserId !== '' && Str::contains((string) ($event['text'] ?? ''), "<@{$botUserId}>"),
        );
    }

    /** @param  array<string, mixed>  $event */
    protected function messageUpdatedEvent(array $event, string $botUserId, ?string $eventId): MessageUpdated
    {
        $message = (array) ($event['message'] ?? []);
        $channel = (string) ($event['channel'] ?? '');
        $threadTs = $message['thread_ts'] ?? $message['ts'] ?? null;
        $thread = new Thread($this->connection(), $channel, $threadTs);

        return new MessageUpdated(
            $this->normalizeMessage($message, $thread, $eventId, isDirectMessage: $this->isDirectMessage($event), authorizedUserId: isset($message['user']) ? $botUserId : ''),
        );
    }

    /** @param  array<string, mixed>  $event */
    protected function messageDeletedEvent(array $event, string $botUserId, ?string $eventId): MessageDeleted
    {
        $previous = (array) ($event['previous_message'] ?? []);
        $message = (array) ($event['message'] ?? []);
        $messageId = (string) ($event['deleted_ts'] ?? $previous['ts'] ?? $message['ts'] ?? '');
        $channel = (string) ($event['channel'] ?? '');
        $threadTs = $previous['thread_ts'] ?? $previous['ts'] ?? $message['thread_ts'] ?? $message['ts'] ?? ($messageId !== '' ? $messageId : null);
        $thread = new Thread($this->connection(), $channel, $threadTs);
        $userId = (string) ($previous['user'] ?? '');

        return new MessageDeleted(
            thread: $thread,
            messageId: $messageId,
            user: new User($userId, isSelf: $userId !== '' && $userId === $botUserId),
            deletedAt: $this->timestamp((string) ($event['event_ts'] ?? $event['ts'] ?? '')),
            eventId: $eventId,
            raw: $event,
            isDirectMessage: $this->isDirectMessage($event),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function messageEvent(array $event, string $botUserId, ?string $eventId, bool $isMention): Message
    {
        $channel = (string) ($event['channel'] ?? '');
        $ts = (string) ($event['ts'] ?? '');
        $thread = new Thread($this->connection(), $channel, $event['thread_ts'] ?? ($ts !== '' ? $ts : null));

        return $this->normalizeMessage(
            $event,
            $thread,
            $eventId,
            $isMention,
            $this->isDirectMessage($event),
            isset($event['user']) ? $botUserId : '',
        );
    }

    /** @param  array<string, mixed>  $event */
    protected function isDirectMessage(array $event): bool
    {
        return ($event['channel_type'] ?? null) === 'im';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function normalizeMessage(array $message, Thread $thread, ?string $eventId = null, bool $isMention = false, bool $isDirectMessage = false, string $authorizedUserId = ''): Message
    {
        $ts = (string) ($message['ts'] ?? '');
        $userId = (string) ($message['user'] ?? $message['bot_id'] ?? '');
        $botId = (string) ($message['bot_id'] ?? '');
        $appId = (string) (data_get($message, 'bot_profile.app_id') ?? $message['app_id'] ?? '');

        return Message::make(
            thread: $thread,
            text: (string) ($message['text'] ?? ''),
            user: new User(
                id: $userId,
                name: $message['username'] ?? null,
                isBot: $botId !== '',
                isSelf: $this->isSelf($userId, $botId, $appId, $authorizedUserId),
            ),
            sentAt: $this->timestamp($ts),
        )->withMention($isMention)
            ->withDirectMessage($isDirectMessage)
            ->withId($ts !== '' ? $ts : null)
            ->withEventId($eventId)
            ->withEditedAt($this->editedAt($message))
            ->withAttachments($this->attachments($message));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return Collection<int, Attachment>
     */
    protected function attachments(array $message): Collection
    {
        return collect((array) ($message['files'] ?? []))
            ->filter(fn (mixed $file): bool => is_array($file)
                && ($file['url_private'] ?? '') !== ''
                && ! in_array($file['mode'] ?? null, static::IGNORED_FILE_MODES, true))
            ->map(fn (array $file): Attachment => new Attachment(
                type: AttachmentType::fromMimeType($mimeType = (string) ($file['mimetype'] ?? Attachment::DEFAULT_MIME_TYPE)),
                mimeType: $mimeType,
                name: $file['name'] ?? null,
                size: isset($file['size']) ? (int) $file['size'] : null,
                id: (string) ($file['id'] ?? '') ?: null,
                url: (string) $file['url_private'],
            ))
            ->values();
    }

    public function downloadAttachment(Attachment $attachment, int $maxBytes, int $timeout): string
    {
        $url = (string) $attachment->url();

        $downloader = $this->downloader();

        if (! $downloader->trusted($url, static::FILE_HOSTS)) {
            throw new AttachmentException('Refusing to download a Slack attachment from an untrusted host.');
        }

        $response = $downloader->request($url, ['Authorization' => 'Bearer '.$this->token('bot_token')], $maxBytes, $timeout);

        if (Str::contains(Str::lower((string) $response->header('Content-Type')), 'text/html')) {
            throw new AttachmentException('Slack returned a sign-in page instead of file bytes. Add the [files:read] scope and reinstall the app.');
        }

        return $downloader->read($response, $maxBytes);
    }

    public function sendAttachment(Thread $thread, Attachment $attachment, Card|Markdown|string|null $caption = null): SentMessage
    {
        $contents = $attachment->contents();
        $name = $attachment->label();

        $upload = $this->api('files.getUploadURLExternal', [
            'filename' => $name,
            'length' => strlen($contents),
        ], asForm: true);

        $uploadUrl = (string) ($upload['upload_url'] ?? '');

        if (! $this->downloader()->trusted($uploadUrl, static::UPLOAD_HOSTS)) {
            throw new AttachmentException('Slack returned an untrusted attachment upload URL.');
        }

        Http::setHandler($this->handler())
            ->timeout((int) config('converse.attachments.timeout'))
            ->attach('file', $contents, $name)
            ->post($uploadUrl)
            ->throw();

        $result = $this->api('files.completeUploadExternal', Arr::whereNotNull([
            'files' => [['id' => (string) ($upload['file_id'] ?? ''), 'title' => $name]],
            'channel_id' => $thread->channel()->id(),
            'thread_ts' => $thread->id(),
            'initial_comment' => $caption === null ? null : $this->caption($caption),
        ]));

        return new SentMessage($thread, $this->sharedTimestamp($result, $thread), $result);
    }

    protected function caption(Card|Markdown|string $caption): string
    {
        return match (true) {
            $caption instanceof Card => BlockKitRenderer::escape($caption->fallbackText()),
            $caption instanceof Markdown => $caption->content(),
            default => BlockKitRenderer::escape($caption),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function sharedTimestamp(array $result, Thread $thread): string
    {
        $shares = (array) data_get($result, 'files.0.shares', []);

        $timestamp = (string) (data_get($shares, "public.{$thread->channel()->id()}.0.ts")
            ?? data_get($shares, "private.{$thread->channel()->id()}.0.ts")
            ?? '');

        return $timestamp !== '' ? $timestamp : (string) data_get($result, 'files.0.id', '');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function reactionEvent(array $event, string $botUserId, bool $added = true): Reaction
    {
        $item = (array) ($event['item'] ?? []);
        $ts = (string) ($item['ts'] ?? '');
        $userId = (string) ($event['user'] ?? '');

        return new Reaction(
            thread: new Thread($this->connection(), (string) ($item['channel'] ?? ''), $ts !== '' ? $ts : null),
            emoji: (string) ($event['reaction'] ?? ''),
            user: new User($userId, isSelf: $userId !== '' && $userId === ($botUserId !== '' ? $botUserId : $this->botUserId)),
            messageId: $ts !== '' ? $ts : null,
            added: $added,
            eventId: isset($event['event_ts']) ? (string) $event['event_ts'] : null,
            raw: $event,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Action>
     */
    protected function actionEvents(array $payload): iterable
    {
        $container = (array) ($payload['container'] ?? []);
        $message = (array) ($payload['message'] ?? []);
        $channel = (string) ($payload['channel']['id'] ?? $container['channel_id'] ?? '');
        $messageId = $message['ts'] ?? $container['message_ts'] ?? null;
        $threadTs = $message['thread_ts'] ?? $container['thread_ts'] ?? $messageId;
        $user = $this->interactivityUser($payload);

        foreach ($payload['actions'] ?? [] as $action) {
            $actionId = (string) ($action['action_id'] ?? '');

            if (str_starts_with($actionId, BlockKitRenderer::LINK_ACTION_PREFIX)) {
                continue;
            }

            yield new Action(
                thread: new Thread($this->connection(), $channel, $threadTs),
                actionId: $actionId,
                user: $user,
                value: $action['value'] ?? $action['selected_option']['value'] ?? null,
                messageId: $messageId,
                eventId: isset($action['action_ts']) ? (string) $action['action_ts'] : null,
                raw: $payload,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function commandEvent(array $payload): Command
    {
        return new Command(
            thread: new Thread($this->connection(), (string) ($payload['channel_id'] ?? '')),
            name: Str::ltrim((string) $payload['command'], '/'),
            user: new User(
                id: (string) ($payload['user_id'] ?? ''),
                username: $payload['user_name'] ?? null,
            ),
            text: (string) ($payload['text'] ?? ''),
            eventId: isset($payload['trigger_id']) ? (string) $payload['trigger_id'] : null,
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function interactivityUser(array $payload): User
    {
        $user = (array) ($payload['user'] ?? []);

        return new User(
            id: (string) ($user['id'] ?? ''),
            name: $user['name'] ?? null,
            username: $user['username'] ?? null,
        );
    }

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        return $this->postMessage($thread, $content);
    }

    /** @see https://docs.slack.dev/messaging/unfurling-links-in-messages */
    public function requestApproval(Thread $thread, Approval $approval): SentMessage
    {
        return $this->postMessage($thread, $this->approvalCard($approval), ['unfurl_links' => false, 'unfurl_media' => false]);
    }

    /**
     * @param  array<string, bool>  $options
     */
    protected function postMessage(Thread $thread, Card|Markdown|string $content, array $options = []): SentMessage
    {
        $result = $this->api('chat.postMessage', array_replace($this->payload($thread, $content), Arr::whereNotNull([
            'thread_ts' => $thread->id(),
        ]), $options));

        return new SentMessage($thread, (string) ($result['ts'] ?? ''), $result);
    }

    public function openDirectMessage(string $recipient): Thread
    {
        $result = $this->api('conversations.open', ['users' => $recipient]);
        $channel = (string) data_get($result, 'channel.id', '');

        if ($channel === '') {
            throw new DriverException('Unable to resolve a Slack direct message channel.');
        }

        return new Thread($this->connection(), $channel, directMessage: true);
    }

    public function sendEphemeralMessage(Thread $thread, Card|Markdown|string $content, User $to): SentMessage
    {
        $result = $this->api('chat.postEphemeral', array_replace($this->payload($thread, $content), Arr::whereNotNull([
            'thread_ts' => $thread->id(),
            'user' => $to->id(),
        ])));

        return new SentMessage($thread, (string) ($result['message_ts'] ?? ''), $result);
    }

    public function editMessage(Thread $thread, string $messageId, Card|Markdown|string $content): SentMessage
    {
        $payload = $this->payload($thread, $content);

        if ($content instanceof Markdown) {
            $payload = [
                'channel' => $payload['channel'],
                'text' => BlockKitRenderer::escape($content->content()),
                'blocks' => [['type' => 'markdown', 'text' => BlockKitRenderer::markdown($content->content())]],
            ];
        }

        $result = $this->api('chat.update', array_replace($payload, ['ts' => $messageId]));

        return new SentMessage($thread, $messageId, $result);
    }

    public function deleteMessage(Thread $thread, string $messageId): void
    {
        $this->api('chat.delete', [
            'channel' => $thread->channel()->id(),
            'ts' => $messageId,
        ]);
    }

    public function updateStatus(Thread $thread, ThreadStatus|string|null $status = null): void
    {
        if ($thread->id() === null) {
            return;
        }

        $status ??= ThreadStatus::Thinking;

        $this->api('assistant.threads.setStatus', [
            'channel_id' => $thread->channel()->id(),
            'thread_ts' => $thread->id(),
            'status' => $status instanceof ThreadStatus ? $status->value : $status,
            ...is_string($status) ? ['loading_messages' => [$status]] : [],
        ]);
    }

    public function addReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->react('reactions.add', $thread, $messageId, $emoji);
    }

    public function removeReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->react('reactions.remove', $thread, $messageId, $emoji);
    }

    protected function react(string $method, Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->api($method, [
            'channel' => $thread->channel()->id(),
            'timestamp' => $messageId,
            'name' => Emoji::shortcode($emoji),
        ]);
    }

    /**
     * @return iterable<int, Message>
     */
    public function listMessages(Thread $thread, ?int $limit = null, ?string $cursor = null): iterable
    {
        $limit = $limit === null ? static::MAX_MESSAGES : max(1, min($limit, static::MAX_MESSAGES));

        if ($thread->id() === null) {
            return LazyCollection::make(fn (): Generator => $this->historyPages($thread, $cursor, $limit));
        }

        return LazyCollection::make(fn (): Generator => $this->reversedTail(
            $this->historyPages($thread, $cursor, static::MAX_MESSAGES),
            $limit,
        ));
    }

    /**
     * @return Generator<int, Message>
     */
    protected function historyPages(Thread $thread, ?string $latest, int $limit): Generator
    {
        $messages = $this->paginate($thread->id() !== null ? 'conversations.replies' : 'conversations.history', [
            'channel' => $thread->channel()->id(),
            'ts' => $thread->id(),
            'latest' => $latest,
        ], 'messages', $limit);

        foreach ($messages as $message) {
            yield $this->normalizeMessage($message, $thread);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return Generator<int, array<string, mixed>>
     */
    protected function paginate(string $method, array $parameters, string $key, int $limit = PHP_INT_MAX): Generator
    {
        $cursor = null;
        $seen = [];

        do {
            $result = $this->api($method, Arr::whereNotNull([
                ...$parameters,
                'limit' => min($limit, static::PER_PAGE),
                'cursor' => $cursor,
            ]), asForm: true);

            foreach ((array) ($result[$key] ?? []) as $item) {
                if (is_array($item)) {
                    yield $item;

                    if (--$limit === 0) {
                        return;
                    }
                }
            }

            $seen[$cursor ?? ''] = true;
            $cursor = (string) data_get($result, 'response_metadata.next_cursor', '');
        } while ($cursor !== '' && ! isset($seen[$cursor]));
    }

    /**
     * @param  Generator<int, Message>  $messages
     * @return Generator<int, Message>
     */
    protected function reversedTail(Generator $messages, int $limit): Generator
    {
        $tail = [];

        foreach ($messages as $message) {
            $tail[] = $message;

            if (count($tail) > $limit) {
                array_shift($tail);
            }
        }

        yield from array_reverse($tail);
    }

    protected function isSelf(string $userId, string $botId, string $appId, string $authorizedUserId): bool
    {
        if ($authorizedUserId !== '') {
            return $userId === $authorizedUserId;
        }

        if ($appId !== '' && $appId === $this->appId) {
            return true;
        }

        $this->resolveBotIdentity();

        return ($userId !== '' && $userId === $this->botUserId)
            || ($botId !== '' && $botId === $this->botId)
            || ($appId !== '' && $appId === $this->appId);
    }

    protected function resolveBotIdentity(): void
    {
        if ($this->botIdentityResolved) {
            return;
        }

        $this->botId = (string) ($this->config['bot_id'] ?? '');

        try {
            $result = $this->api('auth.test', []);
        } catch (HttpClientException|ConverseException $exception) {
            report($exception);

            return;
        }

        $this->botIdentityResolved = true;
        $this->botId = $this->botId !== '' ? $this->botId : (string) ($result['bot_id'] ?? '');
        $this->botUserId = $this->botUserId !== '' ? $this->botUserId : (string) ($result['user_id'] ?? '');
        $this->appId = $this->appId !== '' ? $this->appId : (string) ($result['app_id'] ?? '');
    }

    protected function timestamp(string $timestamp): CarbonImmutable
    {
        [$seconds, $microseconds] = array_pad(explode('.', $timestamp, 2), 2, '0');

        return CarbonImmutable::createFromTimestamp((int) $seconds, 'UTC')
            ->addMicroseconds((int) str_pad($microseconds, 6, '0'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function editedAt(array $message): ?CarbonImmutable
    {
        $timestamp = (string) data_get($message, 'edited.ts', '');

        return $timestamp === '' ? null : $this->timestamp($timestamp);
    }

    public function getChannel(string $id): Channel
    {
        $result = $this->api('conversations.info', [
            'channel' => $id,
            'include_num_members' => true,
        ], asForm: true);
        $found = (array) ($result['channel'] ?? []);

        return new Channel(
            id: (string) ($found['id'] ?? $id),
            name: isset($found['name']) ? (string) $found['name'] : null,
            isDirectMessage: (bool) ($found['is_im'] ?? false),
            memberCount: isset($found['num_members']) ? (int) $found['num_members'] : null,
        );
    }

    public function findChannel(string $name): ?Channel
    {
        $name = ltrim($name, '#@');

        try {
            return $this->channelNamed($name, 'public_channel,private_channel');
        } catch (DriverException $driverException) {
            if (! str_contains($driverException->getMessage(), 'missing_scope')) {
                throw $driverException;
            }

            return $this->channelNamed($name, 'public_channel');
        }
    }

    protected function channelNamed(string $name, string $types): ?Channel
    {
        $channels = $this->paginate('conversations.list', ['exclude_archived' => true, 'types' => $types], 'channels');

        foreach ($channels as $channel) {
            if (($channel['name'] ?? null) === $name && ($channel['id'] ?? '') !== '') {
                $id = (string) $channel['id'];

                return new Channel($id, $name, false, null, fn (): Channel => $this->getChannel($id));
            }
        }

        return null;
    }

    public function findUser(string $username): ?User
    {
        $username = ltrim($username, '@');
        $displayNamed = [];

        foreach ($this->paginate('users.list', [], 'members') as $member) {
            if (($member['id'] ?? '') === '' || ($member['deleted'] ?? false) === true) {
                continue;
            }

            if (($member['name'] ?? null) === $username) {
                return $this->member($member);
            }

            if (data_get($member, 'profile.display_name') === $username) {
                $displayNamed[] = $member;
            }
        }

        return count($displayNamed) === 1 ? $this->member($displayNamed[0]) : null;
    }

    /**
     * @param  array<string, mixed>  $member
     */
    protected function member(array $member): User
    {
        return new User(
            id: (string) $member['id'],
            name: (string) (data_get($member, 'profile.display_name') ?: ($member['real_name'] ?? null)) ?: null,
            username: $member['name'] ?? null,
            isBot: (bool) ($member['is_bot'] ?? false),
        );
    }

    public function mention(User|string $user): Markdown
    {
        return Markdown::make('<@'.$this->userId($user).'>');
    }

    public function getUser(string $id): ?User
    {
        $result = rescue(fn (): array => $this->api('users.info', ['user' => $id], asForm: true), [], report: false);
        $user = (array) ($result['user'] ?? []);

        return $user === [] ? null : $this->member([...$user, 'id' => $user['id'] ?? $id]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Thread $thread, Card|Markdown|string $content): array
    {
        $payload = match (true) {
            $content instanceof Card => $this->forThread($thread, fn (): array => BlockKitRenderer::render($content)),
            $content instanceof Markdown => ['markdown_text' => BlockKitRenderer::markdown($content->content())],
            default => ['text' => BlockKitRenderer::escape($content)],
        };
        unset($payload['channel'], $payload['thread_ts'], $payload['user'], $payload['ts']);

        return array_replace($payload, ['channel' => $thread->channel()->id()]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function api(string $method, array $params, bool $asForm = false): array
    {
        $request = Http::withToken($this->token('bot_token'))
            ->timeout(static::API_TIMEOUT)
            ->setHandler($this->handler());

        $response = ($asForm ? $request->asForm() : $request->asJson())
            ->post("https://slack.com/api/{$method}", $params)
            ->throw()
            ->json();

        if (is_array($response) && static::isTooLong($response)) {
            throw new InvalidCardException("Slack API call [{$method}] rejected the message as too long.");
        }

        if (! is_array($response) || ($response['ok'] ?? false) !== true) {
            throw new DriverException(sprintf(
                'Slack API call [%s] failed with error [%s].',
                $method,
                is_array($response) ? ($response['error'] ?? 'unknown error') : 'invalid response',
            ));
        }

        return $response;
    }

    /**
     * @param  array<mixed>  $response
     *
     * @see https://docs.slack.dev/reference/methods/chat.postMessage
     */
    protected static function isTooLong(array $response): bool
    {
        return ($response['error'] ?? null) === 'msg_blocks_too_long';
    }
}
