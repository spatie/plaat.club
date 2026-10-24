<?php

declare(strict_types=1);

namespace Laravel\Converse\Runs;

use Illuminate\Support\Collection;
use Laravel\Converse\Attachment;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\RunEvents;
use Laravel\Converse\SentMessage;

class HeldRunEvents implements RunEvents
{
    /** @var list<array<string, mixed>> */
    protected array $held = [];

    /** @var list<array{SentMessage, string, Collection<int, Attachment>}> */
    protected array $replies = [];

    /**
     * @param  array<string, mixed>  $event
     */
    public function write(array $event): void
    {
        $this->held[] = $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->held;
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     */
    public function holdReply(SentMessage $sent, string $text, Collection $attachments): void
    {
        $this->replies[] = [$sent, $text, $attachments];
    }

    public function storeReplies(ConversationStore $conversations): void
    {
        foreach ($this->replies as [$sent, $text, $attachments]) {
            $conversations->storeBotMessage($sent, $text, $attachments);
        }

        $this->replies = [];
    }
}
