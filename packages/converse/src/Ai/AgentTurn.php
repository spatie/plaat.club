<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Laravel\Ai\AgentUserInteraction\Chat;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore as AiConversationStore;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Turn;
use Laravel\Converse\Runs\RunEventWriter;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;
use Throwable;

class AgentTurn implements Turn
{
    protected ?AgentEventStream $stream = null;

    protected ?string $promptMessageId = null;

    /**
     * @param  Agent&RemembersConversations  $agent
     */
    public function __construct(
        protected Agent $agent,
        protected Chat $input,
        protected Thread $thread,
        protected string $runId,
        protected object $participant,
        protected RunWriters $writers,
        protected ThreadContext $context,
        protected ?string $conversationId = null,
        protected ?string $userMessageId = null,
        protected ?string $clientMessageId = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recordPrompt(): array
    {
        $conversationId = $this->continueConversation();

        $message = $this->input->message();

        if (! $message instanceof UserMessage) {
            return [];
        }

        $this->userMessageId = $this->storePrompt($conversationId, $message);
        $this->promptMessageId = $this->userMessageId;

        return TurnEvents::message($this->promptMessageId, 'user', $message->content ?? '', clientMessageId: $this->clientMessageId);
    }

    public function promptMessageId(): ?string
    {
        return $this->promptMessageId;
    }

    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    public function userMessageId(): ?string
    {
        return $this->userMessageId;
    }

    public function run(RunEventWriter $events): void
    {
        $this->continueRecordedConversation();

        $this->writers->writingTo($this->thread, $events, fn () => $this->context->for(
            $this->thread,
            fn () => $this->withRecordedPrompt(fn () => $this->streamTo($events)),
        ));
    }

    protected function streamTo(RunEventWriter $events): void
    {
        foreach ($this->stream()->events($this->agent->stream($this->input)) as $event) {
            $events->write($event);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function failed(Throwable $exception): array
    {
        return iterator_to_array($this->stream()->errorEvents($exception), false);
    }

    protected function stream(): AgentEventStream
    {
        return $this->stream ??= new AgentEventStream($this->thread->id(), $this->runId);
    }

    protected function continueRecordedConversation(): void
    {
        if ($this->conversationId === null) {
            return;
        }

        $this->agent->continue($this->conversationId, as: $this->participant);
    }

    protected function continueConversation(): string
    {
        $conversationId = $this->conversations()->agentConversation($this->thread) ?? $this->startConversation();

        if (! $this->belongsToParticipant($conversationId)) {
            throw new AuthorizationException('This conversation belongs to someone else.');
        }

        $this->agent->continue($conversationId, as: $this->participant);

        $this->conversationId = $conversationId;

        return $conversationId;
    }

    protected function startConversation(): string
    {
        $title = Str::limit($this->input->message()->content ?? '', 50, preserveWords: true);
        $conversationId = $this->aiConversations()->storeConversation($this->participantType(), $this->participantKey(), $title);

        $this->conversations()->putAgentConversation($this->thread, $conversationId);

        return $conversationId;
    }

    protected function storePrompt(string $conversationId, UserMessage $message): string
    {
        $unanswered = $this->unansweredPrompt($conversationId, $message);

        if ($unanswered !== null) {
            return $unanswered;
        }

        return $this->aiConversations()->storeUserMessage(
            $conversationId, $this->participantType(), $this->participantKey(), $this->agent::class, $message,
        );
    }

    protected function unansweredPrompt(string $conversationId, UserMessage $message): ?string
    {
        $newest = AgentResolver::newestStoredMessage($this->aiConversations(), $conversationId);

        if (! $newest instanceof StoredMessage || $newest->role !== 'user') {
            return null;
        }

        if ($newest->content !== $message->content || count($newest->attachments) !== $message->attachments->count()) {
            return null;
        }

        return $newest->id;
    }

    protected function withRecordedPrompt(Closure $callback): void
    {
        if ($this->conversationId === null || $this->userMessageId === null) {
            $callback();

            return;
        }

        $container = Container::getInstance();
        $store = $container->make(AiConversationStore::class);
        $shared = $container->isShared(AiConversationStore::class);

        $container->instance(AiConversationStore::class, new RecordedPromptConversationStore(
            $store, $this->conversationId, $this->userMessageId, $this->input->message()->content ?? '',
        ));

        try {
            $callback();
        } finally {
            $this->restoreConversationStore($container, $store, $shared);
        }
    }

    protected function restoreConversationStore(Container $container, AiConversationStore $store, bool $shared): void
    {
        if (! $shared) {
            $container->forgetInstance(AiConversationStore::class);

            return;
        }

        $container->instance(AiConversationStore::class, $store);
    }

    protected function belongsToParticipant(string $conversationId): bool
    {
        $store = $this->aiConversations();

        if (! $store instanceof VerifiesConversationOwnership) {
            return true;
        }

        return $store->conversationBelongsTo($conversationId, $this->participantType(), $this->participantKey());
    }

    protected function participantType(): string
    {
        return Conversation::participantType($this->participant);
    }

    protected function participantKey(): string|int
    {
        return Conversation::participantKey($this->participant);
    }

    protected function conversations(): ConversationStore
    {
        return Container::getInstance()->make(ConversationStore::class);
    }

    protected function aiConversations(): AiConversationStore
    {
        return Container::getInstance()->make(AiConversationStore::class);
    }
}
