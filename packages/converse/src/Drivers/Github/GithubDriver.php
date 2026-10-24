<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Github;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Laravel\Converse\Approval;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\MessageDeleted;
use Laravel\Converse\BotEvents\MessageUpdated;
use Laravel\Converse\Cards\Actions;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\CardElement;
use Laravel\Converse\Cards\Text;
use Laravel\Converse\Channel;
use Laravel\Converse\Concerns\BuildsCacheKeys;
use Laravel\Converse\Contracts\Drivers\ReceivesWebhooks;
use Laravel\Converse\Contracts\Drivers\SupportsAddReactions;
use Laravel\Converse\Contracts\Drivers\SupportsDeleteMessages;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\Contracts\Drivers\SupportsFindChannels;
use Laravel\Converse\Contracts\Drivers\SupportsFindUsers;
use Laravel\Converse\Contracts\Drivers\SupportsGetChannels;
use Laravel\Converse\Contracts\Drivers\SupportsGetUsers;
use Laravel\Converse\Contracts\Drivers\SupportsListMessages;
use Laravel\Converse\Contracts\Drivers\SupportsRemoveReactions;
use Laravel\Converse\Drivers\Driver;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Exceptions\DriverException;
use Laravel\Converse\Exceptions\DriverRequestException;
use Laravel\Converse\Exceptions\InvalidCardException;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class GithubDriver extends Driver implements ReceivesWebhooks, SupportsAddReactions, SupportsDeleteMessages, SupportsEditMessages, SupportsFindChannels, SupportsFindUsers, SupportsGetChannels, SupportsGetUsers, SupportsListMessages, SupportsRemoveReactions
{
    use BuildsCacheKeys;

    protected const string BASE_URL = 'https://api.github.com';

    protected const string API_VERSION = '2022-11-28';

    protected const string REVIEW_THREAD = 'review';

    protected const string ISSUE = 'issue';

    protected const string COMMENT = 'comment';

    protected const string REVIEW_COMMENT = 'review_comment';

    protected const int PER_PAGE = 100;

    protected const int TOKEN_TTL = 3000;

    protected const int STABLE_TTL = 86400;

    /** @var array<int, string> */
    protected const array REACTIONS = ['+1', '-1', 'laugh', 'confused', 'heart', 'hooray', 'rocket', 'eyes'];

    public function verify(Request $request): bool
    {
        $secret = $this->token('webhook_secret');

        return $secret !== '' && hash_equals(
            'sha256='.hash_hmac('sha256', $request->getContent(), $secret),
            (string) $request->header('X-Hub-Signature-256'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|MessageUpdated|MessageDeleted>
     */
    public function events(array $payload): iterable
    {
        if (is_string($payload['payload'] ?? null)) {
            $payload = (array) json_decode($payload['payload'], true);
        }

        $repository = (string) data_get($payload, 'repository.full_name', '');

        if ($repository === '') {
            return [];
        }

        if (isset($payload['installation']['id'])) {
            $installation = (string) $payload['installation']['id'];

            $this->cache()->put($this->installationCacheKey($repository), $installation, static::STABLE_TTL);
            $this->cache()->put($this->installationCacheKey(''), $installation, static::STABLE_TTL);
        }

        return match (true) {
            isset($payload['comment'], $payload['issue']) => $this->commentEvents($repository, $payload, static::COMMENT),
            isset($payload['comment'], $payload['pull_request']) => $this->commentEvents($repository, $payload, static::REVIEW_COMMENT),
            default => $this->bodyEvents($repository, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|MessageUpdated|MessageDeleted>
     */
    protected function commentEvents(string $repository, array $payload, string $type): iterable
    {
        $number = (string) data_get($payload, $type === static::COMMENT ? 'issue.number' : 'pull_request.number', '');
        $comment = (array) $payload['comment'];
        $id = (string) ($comment['id'] ?? '');

        if ($number === '' || $id === '') {
            return [];
        }

        $threadId = $type === static::COMMENT
            ? $number
            : $number.':'.static::REVIEW_THREAD.':'.($comment['in_reply_to_id'] ?? $id);

        return $this->messageEvents(
            $comment,
            new Thread($this->connection(), $repository, $threadId),
            $this->messageId($type, $id),
            (string) ($payload['action'] ?? ''),
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|MessageUpdated|MessageDeleted>
     */
    protected function bodyEvents(string $repository, array $payload): iterable
    {
        $issue = (array) ($payload['issue'] ?? $payload['pull_request'] ?? []);
        $number = (string) ($issue['number'] ?? '');
        $action = (string) ($payload['action'] ?? '');

        if ($number === '' || ! in_array($action, ['opened', 'edited', 'deleted'], true)) {
            return [];
        }

        if ($action === 'edited' && (! isset($payload['changes']['body']) || isset($payload['review']))) {
            return [];
        }

        return $this->messageEvents(
            $issue,
            new Thread($this->connection(), $repository, $number),
            $this->messageId(static::ISSUE, $number),
            $action === 'opened' ? 'created' : $action,
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $payload
     * @return iterable<int, Message|MessageUpdated|MessageDeleted>
     */
    protected function messageEvents(array $item, Thread $thread, string $messageId, string $action, array $payload): iterable
    {
        $eventId = $action.':'.$thread->channel()->id().':'.$messageId.($action === 'edited' ? ':'.($item['updated_at'] ?? '') : '');

        if ($action === 'deleted') {
            yield new MessageDeleted(
                thread: $thread,
                messageId: $messageId,
                user: $this->user((array) ($item['user'] ?? [])),
                deletedAt: CarbonImmutable::now('UTC'),
                eventId: $eventId,
                raw: $payload,
            );

            return;
        }

        if (! in_array($action, ['created', 'edited'], true)) {
            return;
        }

        $message = $this->message($item, $thread, $messageId)->withEventId($eventId);

        yield $action === 'edited' ? new MessageUpdated($message) : $message;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function message(array $item, Thread $thread, string $messageId): Message
    {
        $text = (string) ($item['body'] ?? '');
        $createdAt = (string) ($item['created_at'] ?? '');
        $updatedAt = (string) ($item['updated_at'] ?? '');

        return Message::make(
            $thread,
            $text,
            $this->user((array) ($item['user'] ?? [])),
            $this->timestamp($createdAt),
        )
            ->withMention($this->isMention($text))
            ->withId($messageId)
            ->withEditedAt($updatedAt === '' || $updatedAt === $createdAt ? null : $this->timestamp($updatedAt));
    }

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        [$number, $reply] = $this->target($thread);
        $path = $reply === null ? "issues/{$number}/comments" : "pulls/{$number}/comments/{$reply}/replies";

        $result = $this->api('POST', $this->path($thread, $path), ['body' => $this->body($content)]);

        return new SentMessage($thread, $this->messageId($reply === null ? static::COMMENT : static::REVIEW_COMMENT, (string) ($result['id'] ?? '')), $result);
    }

    public function editMessage(Thread $thread, string $messageId, Card|Markdown|string $content): SentMessage
    {
        $result = $this->api('PATCH', $this->commentPath($thread, $messageId), ['body' => $this->body($content)]);

        return new SentMessage($thread, $messageId, $result);
    }

    public function deleteMessage(Thread $thread, string $messageId): void
    {
        if (Str::startsWith($messageId, static::ISSUE.':')) {
            throw new DriverException('GitHub cannot delete the body of an issue or pull request.');
        }

        $this->api('DELETE', $this->commentPath($thread, $messageId));
    }

    public function addReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->api('POST', $this->commentPath($thread, $messageId).'/reactions', ['content' => $this->reaction($emoji)]);
    }

    public function removeReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $path = $this->commentPath($thread, $messageId).'/reactions';
        $content = $this->reaction($emoji);
        $page = 1;

        do {
            $reactions = $this->api('GET', $path, ['content' => $content, 'per_page' => static::PER_PAGE, 'page' => $page++]);

            $reaction = collect($reactions)->first(fn (mixed $reaction): bool => is_array($reaction)
                && ($reaction['content'] ?? null) === $content
                && $this->isSelf((string) data_get($reaction, 'user.login', '')));

            if (is_array($reaction) && isset($reaction['id'])) {
                $this->api('DELETE', $path.'/'.$reaction['id']);

                return;
            }
        } while (count($reactions) === static::PER_PAGE);
    }

    /**
     * @return iterable<int, Message>
     */
    public function listMessages(Thread $thread, ?int $limit = null, ?string $cursor = null): iterable
    {
        if ($thread->id() === null) {
            return LazyCollection::empty();
        }

        $limit = $limit === null ? static::MAX_MESSAGES : max(1, min($limit, static::MAX_MESSAGES));

        return LazyCollection::make(fn (): Generator => $this->threadMessages($thread, $cursor))->take($limit);
    }

    /**
     * @return Generator<int, Message>
     */
    protected function threadMessages(Thread $thread, ?string $before): Generator
    {
        [$number, $reply] = $this->target($thread);
        $type = $reply === null ? static::COMMENT : static::REVIEW_COMMENT;
        $found = $before === null;

        foreach ($this->pagesNewestFirst($this->path($thread, $reply === null ? "issues/{$number}/comments" : "pulls/{$number}/comments")) as $comments) {
            foreach (array_reverse($comments) as $comment) {
                if (! is_array($comment) || ! $this->belongsToThread($comment, $reply)) {
                    continue;
                }

                $id = $this->messageId($type, (string) $comment['id']);

                if ($found) {
                    yield $this->message($comment, $thread, $id);
                }

                $found = $found || $id === $before;
            }
        }

        if ($reply === null && $found) {
            yield $this->message($this->api('GET', $this->path($thread, "issues/{$number}")), $thread, $this->messageId(static::ISSUE, $number));
        }
    }

    /**
     * @return Generator<int, array<int|string, mixed>>
     */
    protected function pagesNewestFirst(string $path): Generator
    {
        $first = $this->send('GET', $path, ['per_page' => static::PER_PAGE]);

        preg_match('/[?&]page=(\d+)[^>]*>;\s*rel="last"/', $first->header('Link'), $last);

        for ($page = (int) ($last[1] ?? 1); $page > 1; $page--) {
            yield $this->api('GET', $path, ['per_page' => static::PER_PAGE, 'page' => $page]);
        }

        yield (array) $first->json();
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    protected function belongsToThread(array $comment, ?string $reply): bool
    {
        return $reply === null || (string) ($comment['in_reply_to_id'] ?? $comment['id'] ?? '') === $reply;
    }

    public function getChannel(string $id): Channel
    {
        return $this->channel($this->api('GET', $this->repositoryPath($id)), $id);
    }

    public function findChannel(string $name): ?Channel
    {
        $repository = $this->find($this->repositoryPath(Str::ltrim($name, '#@/')));

        return isset($repository['full_name']) ? $this->channel($repository, (string) $repository['full_name']) : null;
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    protected function channel(array $repository, string $id): Channel
    {
        return new Channel(
            id: (string) ($repository['full_name'] ?? $id),
            name: isset($repository['name']) ? (string) $repository['name'] : null,
            isDirectMessage: false,
        );
    }

    public function findUser(string $username): ?User
    {
        return $this->getUser($username);
    }

    public function getUser(string $id): ?User
    {
        $user = $this->find('users/'.rawurlencode(Str::ltrim($id, '@')));

        return isset($user['login']) ? $this->user($user) : null;
    }

    public function mention(User|string $user): Markdown
    {
        $login = $user instanceof User ? $user->username() ?? $user->id() : $user;

        return Markdown::make('@'.Str::ltrim($login, '@'));
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function target(Thread $thread): array
    {
        $segments = explode(':', (string) $thread->id());

        if ($segments[0] === '') {
            throw new DriverException('GitHub messages require an issue or pull request thread.');
        }

        return count($segments) === 3 && $segments[1] === static::REVIEW_THREAD
            ? [$segments[0], $segments[2]]
            : [$segments[0], null];
    }

    protected function messageId(string $type, string $id): string
    {
        return $type.':'.$id;
    }

    protected function commentPath(Thread $thread, string $messageId): string
    {
        [$type, $id] = array_pad(explode(':', $messageId, 2), 2, '');

        return $this->path($thread, match ($type) {
            static::COMMENT => "issues/comments/{$id}",
            static::REVIEW_COMMENT => "pulls/comments/{$id}",
            static::ISSUE => "issues/{$id}",
            default => throw new DriverException("Unable to resolve the GitHub comment [{$messageId}]."),
        });
    }

    protected function repositoryPath(string $name): string
    {
        return 'repos/'.implode('/', array_map(rawurlencode(...), explode('/', $name)));
    }

    protected function path(Thread $thread, string $path): string
    {
        return $this->repositoryPath($thread->channel()->id()).'/'.$path;
    }

    protected function approvalCard(Approval $approval): Card
    {
        $card = parent::approvalCard($approval);

        return $card->withChildren(...$card->children()
            ->reject(fn (CardElement $element): bool => $element instanceof Actions)
            ->push(Text::make('Reply `approve` or `reject` to answer.')));
    }

    protected function body(Card|Markdown|string $content): string
    {
        return match (true) {
            $content instanceof Card => CardRenderer::render($content),
            $content instanceof Markdown => $content->content(),
            default => static::escape($content),
        };
    }

    protected static function escape(string $text): string
    {
        return strtr(addcslashes($text, '\\*_~`#>[]|!<&+-'), ['@' => '@&#8203;']);
    }

    protected function reaction(Emoji|string $emoji): string
    {
        if (is_string($emoji) && in_array($emoji, static::REACTIONS, true)) {
            return $emoji;
        }

        return match (Emoji::parse($emoji)) {
            Emoji::ThumbsUp => '+1',
            Emoji::ThumbsDown => '-1',
            Emoji::Laugh => 'laugh',
            Emoji::Thinking => 'confused',
            Emoji::Heart => 'heart',
            Emoji::Party => 'hooray',
            Emoji::Rocket => 'rocket',
            Emoji::Eyes => 'eyes',
            default => throw new DriverException(sprintf('GitHub only supports the reactions [%s].', implode(', ', static::REACTIONS))),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function user(array $data): User
    {
        $login = (string) ($data['login'] ?? '');

        return new User(
            id: $login,
            name: isset($data['name']) ? (string) $data['name'] : null,
            username: $login === '' ? null : $login,
            isBot: ($data['type'] ?? null) === 'Bot',
            isSelf: $this->isSelf($login),
        );
    }

    protected function isSelf(string $login): bool
    {
        return Str::lower($login) === Str::lower($this->botUsername());
    }

    protected function botUsername(): string
    {
        return $this->cache()->remember(
            $this->cacheKey('github-login', null, $this->connection(), $this->token('app_id')),
            static::STABLE_TTL,
            fn (): string => $this->resolveBotUsername(),
        );
    }

    protected function resolveBotUsername(): string
    {
        $slug = (string) $this->sendAsApp('GET', 'app')->json('slug', '');

        if ($slug === '') {
            throw new DriverException('Unable to resolve the GitHub App the bot comments as. Check the [app_id] and [private_key] connection options.');
        }

        return $slug.'[bot]';
    }

    protected function isMention(string $text): bool
    {
        $handle = (string) preg_replace('/\[bot\]$/i', '', $this->botUsername());

        return preg_match('/(?<![\w.-])@'.preg_quote($handle, '/').'(?:\[bot\])?(?![a-z0-9-])/i', $text) === 1;
    }

    protected function timestamp(string $timestamp): CarbonImmutable
    {
        return CarbonImmutable::parse($timestamp === '' ? 'now' : $timestamp, 'UTC');
    }

    /**
     * @return array<string, mixed>
     */
    protected function find(string $path): array
    {
        try {
            return $this->api('GET', $path);
        } catch (DriverRequestException $driverRequestException) {
            if ($driverRequestException->response()->status() !== 404) {
                throw $driverRequestException;
            }

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function api(string $method, string $path, array $payload = []): array
    {
        return (array) $this->send($method, $path, $payload)->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $path, array $payload, bool $retry = true): Response
    {
        $token = $this->accessToken($path);

        try {
            return $this->request($method, $path, $payload, $token);
        } catch (DriverRequestException $driverRequestException) {
            if (! $retry || $driverRequestException->response()->status() !== 401) {
                throw $driverRequestException;
            }

            $this->forgetInstallation($path);

            return $this->send($method, $path, $payload, false);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendAsApp(string $method, string $path, array $payload = []): Response
    {
        return $this->request($method, $path, $payload, $this->jwt());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function request(string $method, string $path, array $payload, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => static::API_VERSION,
                ])
                ->timeout(static::API_TIMEOUT)
                ->setHandler($this->handler())
                ->send($method, $this->url($path), $method === 'GET' ? ['query' => $payload] : ['json' => $payload])
                ->throw();
        } catch (RequestException $requestException) {
            if (static::isTooLong($requestException->response)) {
                throw new InvalidCardException("GitHub API call [{$method} {$path}] rejected the message as too long.", $requestException->getCode(), $requestException);
            }

            throw $this->rejected($method, $path, $requestException);
        }
    }

    /** @see https://docs.github.com/en/rest/issues/comments#create-an-issue-comment */
    protected static function isTooLong(Response $response): bool
    {
        return $response->status() === 422 && collect((array) $response->json('errors', []))
            ->contains(fn (mixed $error): bool => is_array($error) && Str::startsWith((string) ($error['message'] ?? ''), 'Body is too long'));
    }

    protected function accessToken(string $path, bool $retry = true): string
    {
        $installation = $this->installationId($path);

        try {
            return $this->cache()->remember(
                $this->tokenCacheKey($installation),
                static::TOKEN_TTL,
                fn (): string => (string) $this->sendAsApp('POST', "app/installations/{$installation}/access_tokens")->json('token', '')
                    ?: throw new DriverException("GitHub did not issue an access token for the installation [{$installation}]."),
            );
        } catch (DriverRequestException $driverRequestException) {
            if (! $retry) {
                throw $driverRequestException;
            }

            $this->forgetInstallation($path);

            return $this->accessToken($path, false);
        }
    }

    protected function forgetInstallation(string $path): void
    {
        $installation = $this->cache()->pull($this->installationCacheKey($this->repositoryOf($path)));

        if (is_string($installation)) {
            $this->cache()->forget($this->tokenCacheKey($installation));
        }
    }

    protected function repositoryOf(string $path): string
    {
        return preg_match('#^repos/([^/]+/[^/]+)#', $path, $matches) === 1 ? $matches[1] : '';
    }

    protected function tokenCacheKey(string $installation): string
    {
        return $this->cacheKey('github-token', null, $this->connection(), $this->token('app_id'), $installation);
    }

    protected function installationId(string $path): string
    {
        $repository = $this->repositoryOf($path);

        return $this->cache()->remember(
            $this->installationCacheKey($repository),
            static::STABLE_TTL,
            fn (): string => (string) ($repository === ''
                ? $this->sendAsApp('GET', 'app/installations', ['per_page' => 1])->json('0.id', '')
                : $this->sendAsApp('GET', "repos/{$repository}/installation")->json('id', ''))
                ?: throw new DriverException('The GitHub App is not installed on any account.'),
        );
    }

    protected function installationCacheKey(string $repository): string
    {
        return $this->cacheKey('github-installation', null, $this->connection(), $this->token('app_id'), $repository);
    }

    protected function jwt(): string
    {
        $encode = fn (string $value): string => Str::rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $now = time();
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode((string) json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $this->token('app_id'),
        ]));

        $key = openssl_pkey_get_private(Str::replace('\n', "\n", $this->token('private_key')));

        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new DriverException('Unable to sign the GitHub App token. Check the [private_key] connection option.');
        }

        return $unsigned.'.'.$encode((string) $signature);
    }

    protected function cache(): Repository
    {
        return Cache::store(config('converse.cache.store'));
    }

    protected function url(string $path): string
    {
        return Str::rtrim((string) ($this->config['base_url'] ?? static::BASE_URL), '/').'/'.$path;
    }

    protected function rejected(string $method, string $path, RequestException $requestException): DriverRequestException
    {
        return new DriverRequestException(sprintf(
            'GitHub API call [%s %s] failed with status [%d] and error [%s].',
            $method,
            $path,
            $requestException->response->status(),
            (string) $requestException->response->json('message', 'unknown error'),
        ), $requestException->response);
    }
}
