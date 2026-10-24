<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Carbon\CarbonInterface;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore as AiConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Messages\Message as AiMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Ai\AgentResolver;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Thread;

class WebTranscript
{
    public function __construct(
        protected BotRegistrar $registrar,
        protected ConversationStore $conversations,
        protected AiConversationStore $agentConversations,
    ) {}

    /**
     * @internal
     *
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: Cursor|null}
     */
    public function page(Thread $thread, int $perPage, ?Cursor $cursor = null): array
    {
        if (! $this->isAgentThread($thread)) {
            return $this->handlerPage($thread, $perPage, $cursor);
        }

        $agentConversation = $this->agentConversation($thread);

        if (! $this->agentConversations instanceof PaginatesConversations) {
            return $this->unpagedAgentPage($agentConversation, $perPage, $cursor);
        }

        return $this->agentPage($thread, $agentConversation, $perPage, $cursor);
    }

    /** @internal */
    public function newestUserMessageId(Thread $thread): ?string
    {
        if (! $this->isAgentThread($thread)) {
            return $this->newestHandlerUserMessageId($thread);
        }

        $newest = $this->mergedMessages($thread, $this->newestStoredAgentMessages($thread), 1)->first();

        return match (true) {
            $newest instanceof StoredMessage => $newest->role === 'user' ? $newest->id : null,
            $newest instanceof Message => $newest->user()->isSelf() ? null : $newest->id(),
            default => null,
        };
    }

    /**
     * @internal
     *
     * @return list<PendingApproval>|null
     */
    public function pendingApprovals(Thread $thread): ?array
    {
        if (! $this->agentConversations instanceof ResolvesPendingApprovals) {
            return null;
        }

        $agentConversation = $this->agentConversation($thread);

        if ($agentConversation === null) {
            return [];
        }

        return $this->agentConversations->pendingApprovalsFor($agentConversation);
    }

    /** @internal */
    public function approvalsMatch(Thread $thread, Decisions $decisions): bool
    {
        $pending = $this->pendingApprovals($thread);

        if ($pending === null) {
            return true;
        }

        $ids = collect(array_keys($decisions->all()));
        $decided = $ids->reject(fn (string $id): bool => $id === '*');
        $pendingIds = collect($pending)->pluck('id');

        if ($decided->isEmpty() || $decided->diff($pendingIds)->isNotEmpty()) {
            return false;
        }

        return $ids->contains('*') || $pendingIds->diff($decided)->isEmpty();
    }

    protected function newestHandlerUserMessageId(Thread $thread): ?string
    {
        $newest = $this->conversations->messages($thread, 1)->first();

        if (! $newest instanceof Message || $newest->user()->isSelf()) {
            return null;
        }

        return $newest->id();
    }

    /**
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: Cursor|null}
     */
    protected function handlerPage(Thread $thread, int $perPage, ?Cursor $cursor): array
    {
        $fetched = $this->handlerMessagesBefore($thread, $perPage + 1, $cursor)->collect()->reverse()->values();
        $page = $fetched->take(-$perPage)->values();

        return [
            'messages' => array_values($page->map(fn (Message $message): array => $this->handlerMessage($message))->all()),
            'interrupts' => [],
            'nextCursor' => $fetched->count() > $perPage ? $this->nextCursor($thread, $page->all(), $cursor) : null,
        ];
    }

    /**
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: Cursor|null}
     */
    protected function agentPage(Thread $thread, ?string $agentConversation, int $perPage, ?Cursor $cursor): array
    {
        $stored = $this->storedAgentMessages($agentConversation, $perPage + 1, $cursor);

        $merged = collect($stored)
            ->concat($this->handlerMessagesBefore($thread, $perPage + 1, $cursor)->reverse()->values())
            ->sortBy(fn (StoredMessage|Message $message): string => $this->timestamp($message))
            ->take(-($perPage + 1))
            ->values();

        $page = $merged->take(-$perPage)->values();

        return [
            'messages' => array_values($page->flatMap(fn (StoredMessage|Message $message): array => $this->clientMessages($message))->all()),
            'interrupts' => AgentUserInteraction::toClientState($this->conversationMessages($stored))['interrupts'],
            'nextCursor' => $merged->count() > $perPage ? $this->nextCursor($thread, $page->all(), $cursor) : null,
        ];
    }

    /**
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: null}
     */
    protected function unpagedAgentPage(?string $agentConversation, int $perPage, ?Cursor $cursor): array
    {
        $state = AgentUserInteraction::toClientState($this->agentMessages($agentConversation, $perPage, $cursor));

        return ['messages' => $state['messages'], 'interrupts' => $state['interrupts'], 'nextCursor' => null];
    }

    /**
     * @return LazyCollection<int, Message>
     */
    protected function handlerMessagesBefore(Thread $thread, int $limit, ?Cursor $cursor): LazyCollection
    {
        return $this->conversations->messages($thread, $limit, $this->cursorPosition($cursor, 'message'));
    }

    /**
     * @param  array<int, StoredMessage|Message>  $page
     */
    protected function nextCursor(Thread $thread, array $page, ?Cursor $cursor): Cursor
    {
        $oldestStored = Arr::first($page, fn (StoredMessage|Message $message): bool => $message instanceof StoredMessage);
        $oldestHandled = Arr::first($page, fn (StoredMessage|Message $message): bool => $message instanceof Message);

        return new Cursor(array_filter([
            'thread' => $thread->id(),
            'agent' => $oldestStored instanceof StoredMessage ? $oldestStored->id : $this->cursorPosition($cursor, 'agent'),
            'message' => $oldestHandled instanceof Message ? $oldestHandled->id() : $this->cursorPosition($cursor, 'message'),
        ], is_string(...)));
    }

    protected function cursorPosition(?Cursor $cursor, string $source): ?string
    {
        $position = $cursor?->toArray()[$source] ?? null;

        return is_string($position) ? $position : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function handlerMessage(Message $message): array
    {
        return [
            'id' => $message->id() ?? (string) $message->storageId(),
            'role' => $message->user()->isSelf() ? 'assistant' : 'user',
            'content' => $message->text(),
            'createdAt' => $message->sentAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @param  array<int, StoredMessage>  $stored
     * @return Collection<int, StoredMessage|Message>
     */
    protected function mergedMessages(Thread $thread, array $stored, int $limit): Collection
    {
        return collect($stored)
            ->concat($this->conversations->messages($thread, $limit)->reverse()->values())
            ->sortBy(fn (StoredMessage|Message $message): string => $this->timestamp($message))
            ->take(-$limit)
            ->values();
    }

    protected function timestamp(StoredMessage|Message $message): string
    {
        $time = $message instanceof Message ? $message->sentAt() : $message->createdAt;

        return $time === null ? '' : Date::instance($time)->utc()->format('Y-m-d H:i:s.u');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function clientMessages(StoredMessage|Message $message): array
    {
        if ($message instanceof Message) {
            return [$this->handlerMessage($message)];
        }

        $createdAt = $message->createdAt instanceof CarbonInterface ? ['createdAt' => $message->createdAt->format(DATE_ATOM)] : [];
        $assistant = $message->role === 'assistant' ? $this->assistantFields($message) : [];

        return array_map(function (array $clientMessage) use ($createdAt, $assistant): array {
            $clientMessage += $createdAt;

            if ($clientMessage['role'] === 'assistant') {
                $clientMessage += $assistant;
            }

            return $clientMessage;
        }, AgentUserInteraction::toClientState($this->conversationMessages([$message]))['messages']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function assistantFields(StoredMessage $message): array
    {
        $fields = [];

        if ($message->steps !== []) {
            $fields['parts'] = $this->assistantParts($message);
        }

        if ($message->usage !== []) {
            $fields['usage'] = $this->usage($message);
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function assistantParts(StoredMessage $message): array
    {
        $provider = $this->metaString($message, 'provider');

        return array_values(collect($message->steps)
            ->flatMap(fn (array $step): array => $this->stepParts($step, $provider))
            ->concat($this->citationParts($message))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<array<string, mixed>>
     */
    protected function stepParts(array $step, ?string $provider): array
    {
        $reasoning = is_string($step['reasoning'] ?? null) ? $step['reasoning'] : '';
        $text = is_string($step['content'] ?? null) ? $step['content'] : '';
        $toolCalls = is_array($step['tool_calls'] ?? null) ? $step['tool_calls'] : [];
        $providerToolCalls = is_array($step['provider_tool_calls'] ?? null) ? $step['provider_tool_calls'] : [];

        $parts = [];

        if (filled($reasoning)) {
            $parts[] = ['type' => 'reasoning', 'text' => $reasoning];
        }

        foreach ($providerToolCalls as $call) {
            $parts[] = $this->providerToolPart($call, $provider);
        }

        if (filled($text)) {
            $parts[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($toolCalls as $toolCall) {
            $parts[] = ['type' => 'tool', 'toolCallId' => (string) $toolCall['id']];
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array{type: string, name: string, value: array<string, mixed>}
     */
    protected function providerToolPart(array $call, ?string $provider): array
    {
        $type = (string) $call['type'];

        return ['type' => 'custom', 'name' => 'provider-tool', 'value' => [
            'provider' => $provider,
            'itemId' => (string) $call['id'],
            'type' => $type,
            'data' => $call['data'],
            'status' => str_ends_with($type, '_result') ? 'result_received' : 'completed',
        ]];
    }

    /**
     * @return list<array{type: string, name: string, value: array<string, mixed>}>
     */
    protected function citationParts(StoredMessage $message): array
    {
        $citations = is_array($message->meta['citations'] ?? null) ? $message->meta['citations'] : [];

        return array_values(collect($citations)
            ->filter(fn (mixed $citation): bool => is_array($citation) && is_string($citation['url'] ?? null))
            ->map(fn (array $citation): array => ['type' => 'custom', 'name' => 'citation', 'value' => Arr::whereNotNull([
                'url' => $citation['url'],
                'title' => $citation['title'] ?? null,
            ])])
            ->all());
    }

    /**
     * @return array<string, int|string>
     */
    protected function usage(StoredMessage $message): array
    {
        $usage = TextUsage::fromArray($message->usage);

        return Arr::whereNotNull([
            'provider' => $this->metaString($message, 'provider'),
            'model' => $this->metaString($message, 'model'),
            'inputTokens' => $usage->inputTokens,
            'outputTokens' => $usage->outputTokens,
            'totalTokens' => $usage->totalTokens(),
            'reasoningTokens' => $usage->reasoningTokens,
            'cachedInputTokens' => $usage->cacheReadInputTokens,
        ]);
    }

    protected function metaString(StoredMessage $message, string $key): ?string
    {
        $value = $message->meta[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<int, StoredMessage>
     */
    protected function newestStoredAgentMessages(Thread $thread): array
    {
        $agentConversation = $this->agentConversation($thread);

        if ($agentConversation === null) {
            return [];
        }

        $newest = AgentResolver::newestStoredMessage($this->agentConversations, $agentConversation);

        return $newest instanceof StoredMessage ? [$newest] : [];
    }

    /**
     * @return array<int, StoredMessage>
     */
    protected function storedAgentMessages(?string $agentConversation, int $limit, ?Cursor $cursor): array
    {
        if ($agentConversation === null || ! $this->agentConversations instanceof PaginatesConversations) {
            return [];
        }

        $before = $this->cursorPosition($cursor, 'agent');

        $paginated = $this->agentConversations->paginateConversationMessages(
            $agentConversation,
            $limit,
            cursorName: 'converse_agent_cursor',
            cursor: $before === null ? null : new Cursor(['id' => $before]),
        );

        return array_reverse($paginated->items());
    }

    /**
     * @param  array<int, StoredMessage>  $stored
     * @return array<int, ConversationMessage>
     */
    protected function conversationMessages(array $stored): array
    {
        return array_map(fn (StoredMessage $message): ConversationMessage => (new ConversationMessage)->forceFill($message->toArray()), $stored);
    }

    /**
     * @return iterable<int, AiMessage>
     */
    protected function agentMessages(?string $agentConversation, int $limit, ?Cursor $cursor): iterable
    {
        if ($agentConversation === null || $cursor instanceof Cursor) {
            return [];
        }

        return $this->agentConversations->getLatestConversationMessages($agentConversation, $limit);
    }

    protected function agentConversation(Thread $thread): ?string
    {
        return $this->conversations->agentConversation($thread);
    }

    protected function isAgentThread(Thread $thread): bool
    {
        return $this->registrar->get((string) $thread->bot()) instanceof AgentBot;
    }
}
