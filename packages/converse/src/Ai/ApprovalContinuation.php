<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Laravel\Converse\Approval;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\SentMessage;

class ApprovalContinuation
{
    public function __construct(
        protected ?string $user,
        protected Message $message,
        protected string $conversationId,
        protected Approval $approval,
        protected SentMessage $card,
    ) {}

    public function user(): ?string
    {
        return $this->user;
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function conversationId(): string
    {
        return $this->conversationId;
    }

    public function approval(): Approval
    {
        return $this->approval;
    }

    public function card(): SentMessage
    {
        return $this->card;
    }
}
