<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Converse\Ai\AgentParticipant;
use Laravel\Converse\Ai\ApprovalContinuation;
use Laravel\Converse\Attachment;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotEvents\MessageDeleted;
use Laravel\Converse\BotEvents\MessageUpdated;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

interface ConversationStore
{
    public function subscribe(Thread $thread): void;

    public function unsubscribe(Thread $thread): void;

    public function isSubscribed(Thread $thread): bool;

    public function wasStartedByBot(Thread $thread): bool;

    /**
     * @return array<string, mixed>
     */
    public function meta(Thread $thread): array;

    public function putMeta(Thread $thread, string $name, mixed $value): void;

    public function forgetMeta(Thread $thread, string $name): void;

    public function agentConversation(Thread $thread): ?string;

    public function putAgentConversation(Thread $thread, string $conversationId): void;

    public function agentParticipant(Thread $thread): AgentParticipant;

    public function putApproval(Thread $thread, string $token, ApprovalContinuation $continuation, int $seconds): void;

    public function pendingApproval(Thread $thread): ?string;

    public function consumeApproval(Thread $thread, string $token, string $user): ?ApprovalContinuation;

    public function queueUserMessage(Message $message): bool;

    public function storeCommand(Command $command): bool;

    /**
     * @param  Collection<int, Attachment>  $attachments
     */
    public function storeBotMessage(SentMessage $sent, string $text, Collection $attachments = new Collection): void;

    public function updateBotMessage(Thread $thread, string $messageId, string $text): void;

    public function deleteBotMessage(Thread $thread, string $messageId): void;

    public function hasSent(Thread $thread, string $messageId): bool;

    public function updateUserMessage(MessageUpdated $event): bool;

    public function storeUserMessage(MessageUpdated $event): void;

    public function deleteUserMessage(MessageDeleted $event): void;

    /**
     * @return Collection<int, Message>
     */
    public function pendingMessages(Thread $thread): Collection;

    public function hasPendingMessages(Thread $thread): bool;

    public function completeUserMessage(Message $message): void;

    /**
     * @return LazyCollection<int, Message>
     */
    public function messages(Thread $thread, ?int $limit = null, ?string $cursor = null): LazyCollection;
}
