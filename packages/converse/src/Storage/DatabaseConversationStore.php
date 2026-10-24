<?php

declare(strict_types=1);

namespace Laravel\Converse\Storage;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use Laravel\Converse\Ai\AgentParticipant;
use Laravel\Converse\Ai\ApprovalContinuation;
use Laravel\Converse\Attachment;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\MessageDeleted;
use Laravel\Converse\BotEvents\MessageUpdated;
use Laravel\Converse\Casts\UtcMicrosecondTimestamp;
use Laravel\Converse\Concurrency\ThreadCoordinator;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\ConverseManager;
use Laravel\Converse\Enums\MessageRole;
use Laravel\Converse\Enums\MessageStatus;
use Laravel\Converse\Enums\MessageType;
use Laravel\Converse\Enums\ThreadType;
use Laravel\Converse\Exceptions\ShouldNotHappenException;
use Laravel\Converse\Models\Message as MessageModel;
use Laravel\Converse\Models\Thread as ThreadModel;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Storage\Concerns\InteractsWithThreadRows;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class DatabaseConversationStore implements ConversationStore
{
    use InteractsWithThreadRows;

    protected const int MAX_PENDING = 50;

    public function __construct(
        protected ThreadCoordinator $coordinator,
    ) {}

    public function subscribe(Thread $thread): void
    {
        $this->threadRow($thread, create: true)->forceFill(['subscribed_at' => Date::now()])->save();
    }

    public function unsubscribe(Thread $thread): void
    {
        $this->threadRow($thread)?->forceFill(['subscribed_at' => null])->save();

        if ($thread->bot() !== null) {
            $this->threadRow($thread->withBot(null))?->forceFill(['subscribed_at' => null])->save();
        }
    }

    public function isSubscribed(Thread $thread): bool
    {
        return ConverseManager::threadModel()::query()
            ->where(Arr::except($this->threadAttributes($thread), 'bot'))
            ->whereIn('bot', array_unique([$thread->bot() ?? '', '']))
            ->whereNotNull('subscribed_at')
            ->exists();
    }

    public function wasStartedByBot(Thread $thread): bool
    {
        if ($thread->bot() === null || $thread->id() === null) {
            return false;
        }

        $threadRow = ConverseManager::threadModel()::query()
            ->where($this->threadAttributes($thread->forChannel()))
            ->first();

        return $threadRow instanceof ThreadModel && $threadRow->messages()
            ->where('external_id', $thread->id())
            ->where('role', MessageRole::Bot)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(Thread $thread): array
    {
        $meta = $this->ownMeta($thread);

        if ($thread->bot() === null) {
            return $meta;
        }

        return array_filter([...$this->ownMeta($thread->withBot(null)), ...$meta], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ownMeta(Thread $thread): array
    {
        $meta = $this->threadRow($thread)?->meta;

        return is_array($meta) ? $meta : [];
    }

    public function putMeta(Thread $thread, string $name, mixed $value): void
    {
        $this->coordinator->withMetaLock($thread, function () use ($thread, $name, $value): void {
            $meta = $this->ownMeta($thread);

            $meta[$name] = $value;

            $this->threadRow($thread, create: true)->forceFill(['meta' => $meta])->save();
        });
    }

    public function forgetMeta(Thread $thread, string $name): void
    {
        $this->coordinator->withMetaLock($thread, function () use ($thread, $name): void {
            $meta = $this->ownMeta($thread);

            unset($meta[$name]);

            if ($thread->bot() !== null && array_key_exists($name, $this->ownMeta($thread->withBot(null)))) {
                $meta[$name] = null;
            }

            $this->threadRow($thread, create: true)->forceFill(['meta' => $meta])->save();
        });
    }

    public function agentConversation(Thread $thread): ?string
    {
        $conversationId = $this->threadRow($thread)?->agent_conversation_id;

        return is_string($conversationId) ? $conversationId : null;
    }

    public function putAgentConversation(Thread $thread, string $conversationId): void
    {
        $this->threadRow($thread, create: true)
            ->forceFill(['agent_conversation_id' => $conversationId])
            ->save();
    }

    public function agentParticipant(Thread $thread): AgentParticipant
    {
        return new AgentParticipant((int) $this->threadRow($thread, create: true)->getKey());
    }

    public function putApproval(Thread $thread, string $token, ApprovalContinuation $continuation, int $seconds): void
    {
        $this->threadRow($thread, create: true);

        $this->withLockedThreadRow($thread, function (?ThreadModel $threadRow) use ($token, $continuation, $seconds): void {
            $approvals = $this->unexpiredApprovals($threadRow)
                ->map(fn (PausedApproval $approval): PausedApproval => $approval->withoutAwaitingReply())
                ->put($token, new PausedApproval(
                    continuation: ConverseManager::threadModel()::currentEncrypter()->encrypt($continuation),
                    user: $continuation->user(),
                    expiresAt: Date::now()->addSeconds($seconds)->getTimestamp(),
                ));

            $threadRow?->forceFill(['pending_approvals' => $approvals])->save();
        });
    }

    public function pendingApproval(Thread $thread): ?string
    {
        return $this->withLockedThreadRow($thread, function (?ThreadModel $threadRow): ?string {
            $approvals = $this->unexpiredApprovals($threadRow);

            if ($approvals->count() < Collection::make($threadRow?->pending_approvals)->count()) {
                $threadRow?->forceFill(['pending_approvals' => $approvals])->save();
            }

            return $approvals
                ->filter(fn (PausedApproval $approval): bool => $approval->isAwaitingReply())
                ->keys()
                ->first();
        });
    }

    public function consumeApproval(Thread $thread, string $token, string $user): ?ApprovalContinuation
    {
        return $this->withLockedThreadRow($thread, function (?ThreadModel $threadRow) use ($token, $user): ?ApprovalContinuation {
            $approvals = $this->unexpiredApprovals($threadRow);
            $approval = $approvals->get($token);

            if (! $approval instanceof PausedApproval || ! $approval->canBeDecidedBy($user)) {
                return null;
            }

            $continuation = ConverseManager::threadModel()::currentEncrypter()->decrypt($approval->continuation());

            if (! $continuation instanceof ApprovalContinuation) {
                throw new ShouldNotHappenException("Approval [{$token}] holds no continuation.");
            }

            $threadRow?->forceFill(['pending_approvals' => $approvals->except([$token])])->save();

            return $continuation;
        });
    }

    public function queueUserMessage(Message $message): bool
    {
        $threadRow = $this->threadRow($message->thread(), create: true, directMessage: $message->isDirectMessage());
        $row = $this->messageRow($threadRow, $message->id());

        if (! $row instanceof MessageModel) {
            try {
                $threadRow->messages()->create($this->userMessageAttributes($message));
                $threadRow->touch();

                return true;
            } catch (UniqueConstraintViolationException $exception) {
                $row = $this->messageRow($threadRow, $message->id()) ?? throw $exception;
            }
        }

        if ($row->role === MessageRole::Bot || ! $message->isEdited()) {
            return false;
        }

        if ($row->text === $message->text() && $row->external_edited_at?->equalTo($message->editedAt()) === true) {
            return false;
        }

        $row->forceFill([
            ...$this->userMessageAttributes($message),
            'status' => MessageStatus::Pending,
        ])->save();

        $threadRow->touch();

        return ! $row->trashed();
    }

    public function storeCommand(Command $command): bool
    {
        $threadRow = $this->threadRow($command->thread(), create: true, directMessage: $command->isDirectMessage());

        if ($this->messageRow($threadRow, $command->eventId()) instanceof MessageModel) {
            return false;
        }

        try {
            $threadRow->messages()->create([
                'role' => MessageRole::User,
                'external_id' => $command->eventId(),
                'external_user' => $command->user()->toArray(),
                'text' => trim('/'.$command->name().' '.$command->text()),
                'type' => MessageType::Command,
                'external_sent_at' => Date::now(),
                'status' => MessageStatus::Completed,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $threadRow->touch();

        return true;
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     */
    public function storeBotMessage(SentMessage $sent, string $text, Collection $attachments = new Collection): void
    {
        try {
            $threadRow = $this->threadRow($sent->thread(), create: true);

            if ($sent->id() !== '' && $this->messageRow($threadRow, $sent->id()) instanceof MessageModel) {
                return;
            }

            $threadRow->messages()->create([
                'role' => MessageRole::Bot,
                'external_id' => $sent->id() === '' ? null : $sent->id(),
                'external_user' => new User(id: '', name: $sent->thread()->bot(), isBot: true, isSelf: true)->toArray(),
                'text' => $text,
                'type' => MessageType::Message,
                'attachments' => $attachments,
                'external_sent_at' => Date::now(),
                'status' => MessageStatus::Completed,
            ]);

            $threadRow->touch();
        } catch (QueryException $queryException) {
            report($queryException);
        }
    }

    public function updateBotMessage(Thread $thread, string $messageId, string $text): void
    {
        try {
            $this->threadMessageRow($thread, $messageId)?->forceFill([
                'text' => $text,
                'external_edited_at' => Date::now(),
            ])->save();
        } catch (QueryException $queryException) {
            report($queryException);
        }
    }

    public function deleteBotMessage(Thread $thread, string $messageId): void
    {
        try {
            $row = $this->threadMessageRow($thread, $messageId);

            if ($row instanceof MessageModel && ! $row->trashed()) {
                $row->delete();
            }
        } catch (QueryException $queryException) {
            report($queryException);
        }
    }

    public function hasSent(Thread $thread, string $messageId): bool
    {
        $row = $this->threadMessageRow($thread, $messageId);

        return $row instanceof MessageModel && $row->role === MessageRole::Bot && ! $row->trashed();
    }

    public function updateUserMessage(MessageUpdated $event): bool
    {
        if ($event->message()->id() === null) {
            return false;
        }

        $row = $this->messageRow(
            $this->threadRow($event->thread(), directMessage: $event->isDirectMessage()),
            $event->message()->id(),
        );

        if (! $row instanceof MessageModel) {
            return false;
        }

        $row->forceFill([
            'text' => $event->message()->text(),
            'external_edited_at' => $event->message()->editedAt() ?? Date::now(),
            'attachments' => $this->storedAttachments($event->message()),
        ])->save();

        return true;
    }

    public function storeUserMessage(MessageUpdated $event): void
    {
        if ($this->updateUserMessage($event) || $event->message()->id() === null || $event->message()->user()->isSelf()) {
            return;
        }

        $threadRow = $this->threadRow($event->thread(), create: true, directMessage: $event->isDirectMessage());

        try {
            $threadRow->messages()->create([
                ...$this->userMessageAttributes($event->message()),
                'status' => MessageStatus::Completed,
            ]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    public function deleteUserMessage(MessageDeleted $event): void
    {
        $row = $this->messageRow($this->threadRow($event->thread()), $event->messageId());

        if ($row instanceof MessageModel && ! $row->trashed()) {
            $row->delete();
        }
    }

    /**
     * @return Collection<int, Message>
     */
    public function pendingMessages(Thread $thread): Collection
    {
        $threadRow = $this->threadRow($thread);

        if (! $threadRow instanceof ThreadModel) {
            return collect();
        }

        return $this->pendingQuery($threadRow)
            ->orderBy('external_sent_at')
            ->orderBy('id')
            ->limit(static::MAX_PENDING)
            ->get()
            ->map(fn (MessageModel $row): Message => $row->setRelation('thread', $threadRow)->toEvent())
            ->toBase()
            ->values();
    }

    public function hasPendingMessages(Thread $thread): bool
    {
        $threadRow = $this->threadRow($thread);

        return $threadRow instanceof ThreadModel && $this->pendingQuery($threadRow)->exists();
    }

    public function completeUserMessage(Message $message): void
    {
        if ($message->storageId() === null) {
            return;
        }

        ConverseManager::messageModel()::query()
            ->withTrashed()
            ->whereKey($message->storageId())
            ->where('status', MessageStatus::Pending)
            ->when(
                $message->editedAt(),
                fn (Builder $query, DateTimeImmutable $editedAt): Builder => $query->where('external_edited_at', UtcMicrosecondTimestamp::format($editedAt)),
                fn (Builder $query): Builder => $query->whereNull('external_edited_at'),
            )
            ->update(['status' => MessageStatus::Completed]);
    }

    /**
     * @return LazyCollection<int, Message>
     */
    public function messages(Thread $thread, ?int $limit = null, ?string $cursor = null): LazyCollection
    {
        $threadRows = $this->threadRows($thread)->all();

        if ($threadRows === []) {
            return LazyCollection::empty();
        }

        $query = ConverseManager::messageModel()::query()->whereIn('thread_id', array_keys($threadRows));

        if ($cursor !== null && $cursor !== '') {
            $seek = (clone $query)->withTrashed()->where('external_id', $cursor)->value('id');

            if ($seek === null) {
                return LazyCollection::empty();
            }

            $query->where('id', '<', $seek);
        }

        $messages = $query->lazyByIdDesc($limit ?? 1000)
            ->map(fn (MessageModel $row): Message => $row->setRelation('thread', $threadRows[$row->thread_id])->toEvent());

        return $limit === null ? $messages : $messages->take($limit);
    }

    /**
     * @return ($create is true ? ThreadModel : ThreadModel|null)
     */
    protected function threadRow(Thread $thread, bool $create = false, ?bool $directMessage = null): ?ThreadModel
    {
        $directMessage ??= $thread->isDirectMessage() ? true : null;

        $type = $directMessage === null ? null : ThreadType::fromDirectMessage($directMessage);

        $threadRow = $create
            ? ConverseManager::threadModel()::query()->firstOrCreate($this->threadAttributes($thread), ['channel' => $thread->channel()->id(), 'type' => $type ?? ThreadType::Channel])
            : $this->threadQuery($thread)->first();

        if ($threadRow !== null && $type instanceof ThreadType && $threadRow->type !== $type) {
            $threadRow->forceFill(['type' => $type])->save();
        }

        return $threadRow;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(ThreadModel|null): TReturn  $callback
     * @return TReturn
     */
    protected function withLockedThreadRow(Thread $thread, Closure $callback): mixed
    {
        return ConverseManager::threadModel()::query()->getConnection()->transaction(
            fn (): mixed => $callback($this->threadQuery($thread)->lockForUpdate()->first()),
        );
    }

    /**
     * @return Collection<string, PausedApproval>
     */
    protected function unexpiredApprovals(?ThreadModel $threadRow): Collection
    {
        return Collection::make($threadRow?->pending_approvals)
            ->reject(fn (PausedApproval $approval): bool => $approval->isExpired());
    }

    /**
     * @return Collection<int, ThreadModel>
     */
    protected function threadRows(Thread $thread): Collection
    {
        return ConverseManager::threadModel()::query()
            ->where('bot', $thread->bot() ?? '')
            ->where('connection', $thread->connection())
            ->when(
                $thread->id() !== null,
                fn (Builder $query): Builder => $query->where('external_id', $thread->externalId()),
                fn (Builder $query): Builder => $query->where('channel', $thread->channel()->id()),
            )
            ->get()
            ->keyBy(fn (ThreadModel $threadRow): int => (int) $threadRow->getKey());
    }

    protected function threadMessageRow(Thread $thread, string $messageId): ?MessageModel
    {
        $row = $this->messageRow($this->threadRow($thread), $messageId);

        if (! $row instanceof MessageModel && $thread->id() === $messageId) {
            return $this->messageRow($this->threadRow($thread->forChannel()), $messageId);
        }

        return $row;
    }

    protected function messageRow(?ThreadModel $threadRow, ?string $externalId): ?MessageModel
    {
        if (! $threadRow instanceof ThreadModel || $externalId === null || $externalId === '') {
            return null;
        }

        return $threadRow->messages()
            ->withTrashed()
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * @return HasMany<MessageModel, ThreadModel>
     */
    protected function pendingQuery(ThreadModel $threadRow): HasMany
    {
        return $threadRow->messages()->where('role', MessageRole::User)->where('status', MessageStatus::Pending);
    }

    /**
     * @return Collection<int, Attachment>
     */
    protected function storedAttachments(Message $message): Collection
    {
        return $message->attachments()->concat($message->unavailableAttachments());
    }

    /**
     * @return array<string, mixed>
     */
    protected function userMessageAttributes(Message $message): array
    {
        return [
            'role' => MessageRole::User,
            'external_id' => $message->id(),
            'external_user' => $message->user()->toArray(),
            'text' => $message->text(),
            'type' => MessageType::fromMessage($message),
            'attachments' => $this->storedAttachments($message),
            'external_sent_at' => $message->sentAt(),
            'external_edited_at' => $message->editedAt(),
            'status' => MessageStatus::Pending,
        ];
    }
}
