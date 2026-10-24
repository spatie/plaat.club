<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Generator;
use Illuminate\Support\LazyCollection;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Drivers\SupportsListMessages;
use Laravel\Converse\Thread;

class ListMessagesAction extends Action
{
    public function __construct(protected ConversationStore $conversations) {}

    /**
     * @return iterable<int, Message>
     */
    public function handle(Thread $thread, ?int $limit = null, ?string $cursor = null): iterable
    {
        $limit = $limit === null ? SupportsListMessages::MAX_MESSAGES : max(1, min($limit, SupportsListMessages::MAX_MESSAGES));

        $driver = $this->optionalDriverFor($thread, SupportsListMessages::class);

        if (! $driver instanceof SupportsListMessages) {
            return $this->stored($thread, $limit, $cursor);
        }

        return LazyCollection::make(function () use ($driver, $thread, $limit, $cursor): Generator {
            $delivered = false;

            foreach ($driver->listMessages($thread, $limit, $cursor) as $message) {
                $delivered = true;

                yield $message;
            }

            if (! $delivered) {
                yield from $this->stored($thread, $limit, $cursor);
            }
        })->remember()->take($limit);
    }

    /**
     * @return LazyCollection<int, Message>
     */
    protected function stored(Thread $thread, int $limit, ?string $cursor): LazyCollection
    {
        return $this->conversations->messages($thread, $limit, $cursor);
    }
}
