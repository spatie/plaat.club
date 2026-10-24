<?php

declare(strict_types=1);

namespace Laravel\Converse\Events;

use Laravel\Converse\Approval;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

class ApprovalResolved
{
    /**
     * @param  array<string, bool>  $decisions
     */
    public function __construct(
        protected Thread $thread,
        protected Approval $approval,
        protected SentMessage $request,
        protected array $decisions,
    ) {}

    public function thread(): Thread
    {
        return $this->thread;
    }

    public function approval(): Approval
    {
        return $this->approval;
    }

    public function request(): SentMessage
    {
        return $this->request;
    }

    /**
     * @return array<string, bool>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    public function isApproved(): bool
    {
        return Approval::wasApproved($this->decisions);
    }
}
