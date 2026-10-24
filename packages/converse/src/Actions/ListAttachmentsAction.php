<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Illuminate\Support\Collection;
use Laravel\Converse\Attachment;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Thread;

class ListAttachmentsAction extends Action
{
    protected const int MAX_MESSAGES = 500;

    public function __construct(protected ConversationStore $conversations) {}

    /**
     * @return Collection<int, Attachment>
     */
    public function handle(Thread $thread, ?int $limit = null): Collection
    {
        return collect($this->conversations->messages($thread, $limit ?? static::MAX_MESSAGES))
            ->flatMap(fn (Message $message): Collection => $message->attachments())
            ->filter(fn (Attachment $attachment): bool => $attachment->isStored())
            ->unique(fn (Attachment $attachment): string => (string) $attachment->path())
            ->values();
    }
}
