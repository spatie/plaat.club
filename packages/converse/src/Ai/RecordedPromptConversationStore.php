<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class RecordedPromptConversationStore implements ConversationStore
{
    public function __construct(
        protected ConversationStore $store,
        protected string $conversationId,
        protected string $userMessageId,
        protected string $prompt,
    ) {}

    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return $this->store->latestConversationId($participantType, $participantId, $agent);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        return $this->store->storeConversation($participantType, $participantId, $title, $id);
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        if ($conversationId === $this->conversationId && $message->content === $this->prompt) {
            return $this->userMessageId;
        }

        return $this->store->storeUserMessage($conversationId, $participantType, $participantId, $agent, $message);
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        return $this->store->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        if ($conversationId !== $this->conversationId) {
            return $this->store->getLatestConversationMessages($conversationId, $limit);
        }

        $messages = $this->store->getLatestConversationMessages($conversationId, $limit + 1);

        if ($this->endsWithPrompt($messages)) {
            return $messages->slice(0, -1)->values();
        }

        return $messages->values();
    }

    /**
     * @param  array<int, mixed>  $toolResults
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        $this->store->storeApprovalResults($conversationId, $toolResults);
    }

    /**
     * @param  Collection<int, mixed>  $messages
     */
    protected function endsWithPrompt(Collection $messages): bool
    {
        $last = $messages->last();

        return $last instanceof Message && $last->role === MessageRole::User && $last->content === $this->prompt;
    }
}
