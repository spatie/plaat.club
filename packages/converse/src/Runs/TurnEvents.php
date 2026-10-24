<?php

declare(strict_types=1);

namespace Laravel\Converse\Runs;

class TurnEvents
{
    /**
     * @return array<string, mixed>
     */
    public static function runStarted(?string $threadId, string $runId): array
    {
        return ['type' => 'RUN_STARTED', 'threadId' => $threadId, 'runId' => $runId];
    }

    /**
     * @param  list<array<string, mixed>>  $interrupts
     * @return array<string, mixed>
     */
    public static function runFinished(?string $threadId, string $runId, array $interrupts = []): array
    {
        $event = ['type' => 'RUN_FINISHED', 'threadId' => $threadId, 'runId' => $runId];

        if ($interrupts === []) {
            return $event;
        }

        $event['outcome'] = ['type' => 'interrupt', 'interrupts' => $interrupts];

        return $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function message(string $messageId, string $role, string $content, ?string $clientMessageId = null): array
    {
        $start = ['type' => 'TEXT_MESSAGE_START', 'messageId' => $messageId, 'role' => $role];

        if ($clientMessageId !== null) {
            $start['clientMessageId'] = $clientMessageId;
        }

        return [
            $start,
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => $messageId, 'delta' => $content],
            ['type' => 'TEXT_MESSAGE_END', 'messageId' => $messageId],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(): array
    {
        return ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function timedOut(): array
    {
        return ['type' => 'RUN_ERROR', 'message' => 'The response took too long and was stopped.'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cancelled(string $threadId, string $runId): array
    {
        return ['type' => 'RUN_CANCELLED', 'threadId' => $threadId, 'runId' => $runId];
    }
}
