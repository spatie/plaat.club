<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Throwable;

class AgentEventStream extends AgentUserInteractionProtocol
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $heldRunFinished = null;

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function events(StreamableAgentResponse $response): Generator
    {
        foreach ($this->parts($response) as $event) {
            if ($event['type'] === 'RUN_FINISHED') {
                $this->heldRunFinished = $event;

                continue;
            }

            yield $event;
        }

        if ($this->heldRunFinished !== null) {
            yield $this->heldRunFinished + $this->storedMessageIds($response);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function errorEvents(Throwable $exception): Generator
    {
        $this->exception = $exception;

        if ($this->errored) {
            return;
        }

        if ($this->heldRunFinished !== null) {
            $this->finished = false;
        }

        yield from $this->maskedErrorParts();
    }

    /**
     * @return array{messageId?: string, userMessageId?: string}
     */
    protected function storedMessageIds(StreamableAgentResponse $response): array
    {
        return array_filter([
            'messageId' => $response->assistantMessageId,
            'userMessageId' => $response->userMessageId,
        ], fn (?string $id): bool => $id !== null);
    }
}
