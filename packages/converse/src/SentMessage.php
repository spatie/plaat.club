<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Enums\Emoji;

/** @implements Arrayable<string, mixed> */
class SentMessage implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        protected Thread $thread,
        protected string $id,
        protected array $raw = [],
    ) {}

    public function thread(): Thread
    {
        return $this->thread;
    }

    public function withThread(Thread $thread): static
    {
        $sent = clone $this;
        $sent->thread = $thread;

        return $sent;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function edit(Card|Markdown|string $content): SentMessage
    {
        return $this->thread->edit($this->id, $content);
    }

    public function delete(): void
    {
        $this->thread->delete($this->id);
    }

    public function addReaction(Emoji|string $emoji): void
    {
        $this->thread->addReaction($this->id, $emoji);
    }

    public function removeReaction(Emoji|string $emoji): void
    {
        $this->thread->removeReaction($this->id, $emoji);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->thread->toArray(),
            'id' => $this->id,
            'raw' => $this->raw,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
