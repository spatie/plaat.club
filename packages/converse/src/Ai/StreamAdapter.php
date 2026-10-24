<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Stringable;

class StreamAdapter
{
    protected ?string $lastMessageId = null;

    public function text(mixed $chunk): string
    {
        if ($chunk instanceof TextDelta) {
            $boundary = $this->lastMessageId !== null && $chunk->messageId !== $this->lastMessageId;

            $this->lastMessageId = $chunk->messageId;

            return $boundary ? "\n\n".$chunk->delta : $chunk->delta;
        }

        if ($chunk instanceof StreamEvent) {
            return '';
        }

        if (is_string($chunk) || $chunk instanceof Stringable) {
            return (string) $chunk;
        }

        return '';
    }
}
