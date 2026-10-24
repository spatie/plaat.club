<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Pagination\Cursor;
use JsonSerializable;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Bot;
use Laravel\Converse\Thread;

/**
 * @implements Arrayable<string, mixed>
 */
class ChatProps implements Arrayable, Jsonable, JsonSerializable
{
    public const int PER_PAGE = 50;

    protected int $perPage = self::PER_PAGE;

    /**
     * @var array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: Cursor|null, offset: int}|null
     */
    protected ?array $snapshot = null;

    public function __construct(
        protected Bot $bot,
        protected WebDriver $driver,
        protected Thread $thread,
        protected WebThreadSnapshot $snapshots,
        protected WebTranscript $transcript,
    ) {}

    public function perPage(int $perPage): static
    {
        $props = clone $this;

        $props->perPage = max(1, $perPage);
        $props->snapshot = null;

        return $props;
    }

    public function threadId(): string
    {
        return (string) $this->thread->id();
    }

    /**
     * @return array{threadId: string, offset: int, endpoint: string, streamEndpoint: string, messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, transcriptPath: null, capabilities: array<string, bool>}
     */
    public function toArray(): array
    {
        return $this->payload($this->snapshot()['messages']);
    }

    /**
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @template TMessages
     * @template TPath of string|null
     *
     * @param  TMessages  $messages
     * @param  TPath  $transcriptPath
     * @return array{threadId: string, offset: int, endpoint: string, streamEndpoint: string, messages: TMessages, interrupts: list<array<string, mixed>>, transcriptPath: TPath, capabilities: array<string, bool>}
     */
    protected function payload(mixed $messages, ?string $transcriptPath = null): array
    {
        $snapshot = $this->snapshot();

        return [
            'threadId' => $this->threadId(),
            'offset' => $snapshot['offset'],
            'endpoint' => $this->driver->url($this->bot),
            'streamEndpoint' => $this->driver->streamUrl($this->bot),
            'messages' => $messages,
            'interrupts' => $snapshot['interrupts'],
            'transcriptPath' => $transcriptPath,
            'capabilities' => $this->capabilities(),
        ];
    }

    /**
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>, nextCursor: Cursor|null, offset: int}
     */
    protected function snapshot(): array
    {
        return $this->snapshot ??= $this->snapshots->for($this->thread, $this->perPage);
    }

    /**
     * @return array<string, bool>
     */
    protected function capabilities(): array
    {
        if (! $this->bot instanceof AgentBot) {
            return ['streaming' => true];
        }

        return ['streaming' => true, 'approvals' => true, 'retry' => true];
    }
}
