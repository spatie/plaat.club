<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Controllers\Web;

use Generator;
use Laravel\Converse\Drivers\Web\WebChatOptions;
use Laravel\Converse\Drivers\Web\WebThreadStream;
use Laravel\Converse\Enums\WebTransport;
use Laravel\Converse\Http\Requests\Web\WebChatStreamRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebChatStreamController
{
    public function __construct(
        protected WebThreadStream $threads,
    ) {}

    public function __invoke(WebChatStreamRequest $request): StreamedResponse
    {
        $options = $request->options();
        $rows = $this->threads->open($request->thread(), $request->offset(), $request->following(), $options);

        return $this->stream($options, $this->serverSentEvents($rows, $options));
    }

    /**
     * @param  Generator<int, array{sequence: int, payload: array<mixed>}|null, mixed, array{offset: int, live: bool}>  $rows
     * @return Generator<int, string>
     */
    protected function serverSentEvents(Generator $rows, WebChatOptions $options): Generator
    {
        foreach ($rows as $row) {
            if ($this->isDisconnected()) {
                return;
            }

            yield $this->serverSentEvent($row);
        }

        $position = $rows->getReturn();

        yield $this->endOfStream($position['offset'], $position['live'], $options);
    }

    protected function isDisconnected(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * @param  array{sequence: int, payload: array<mixed>}|null  $row
     */
    protected function serverSentEvent(?array $row): string
    {
        if ($row === null) {
            return $this->heartbeat();
        }

        return $this->event($row['sequence'], $row['payload']);
    }

    /**
     * @param  array<mixed>  $payload
     */
    protected function event(int $sequence, array $payload): string
    {
        return "id: {$sequence}\n".$this->data($payload);
    }

    protected function heartbeat(): string
    {
        return ": ping\n\n";
    }

    protected function endOfStream(int $offset, bool $live, WebChatOptions $options): string
    {
        $position = ['reason' => 'up-to-date', 'offset' => $offset, 'live' => $live];

        if ($options->transport() === WebTransport::Poll) {
            $position['poll'] = $options->pollIntervalMilliseconds();
        }

        return "event: converse-stream-end\n".$this->data($position);
    }

    /**
     * @param  array<mixed>  $payload
     */
    protected function data(array $payload): string
    {
        return 'data: '.json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
    }

    /**
     * @param  Generator<int, string>  $events
     */
    protected function stream(WebChatOptions $options, Generator $events): StreamedResponse
    {
        set_time_limit(0);

        return response()->stream(fn (): Generator => yield from $events, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Converse-Follow-Window-Milliseconds' => (string) $options->followWindowMilliseconds(),
        ]);
    }
}
