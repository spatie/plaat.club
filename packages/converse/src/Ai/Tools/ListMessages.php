<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\LazyCollection;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\Drivers\SuppliesAgentHistory;
use Laravel\Converse\Thread;

#[Strict]
class ListMessages implements Tool
{
    use InteractsWithAgentContext;

    protected const int PER_PAGE = 50;

    protected const int MAX_PER_PAGE = 200;

    public function description(): string
    {
        return 'List the message history of the active thread, newest first. Always call this before answering when the request references earlier messages or context you do not already have — summaries, follow-ups, "what did X say", or any prompt that reads like a reply. Page older messages with next_before_message_id. Pass scope "channel" to read top-level channel messages instead of thread replies. Always scoped to the active thread and channel; another one cannot be targeted.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return ! $thread->driver() instanceof SuppliesAgentHistory;
    }

    public function handle(Request $request): string
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.static::MAX_PER_PAGE],
            'before_message_id' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'in:thread,channel'],
        ]);

        $thread = $this->thread();
        $limit = $request->integer('limit', static::PER_PAGE);
        $cursor = $request->string('before_message_id')->value() ?: null;

        $messages = $request->string('scope')->value() === 'channel'
            ? $thread->forChannel()->messages($limit + 1, $cursor)
            : $thread->messages($limit + 1, $cursor);

        return $this->page($messages, $limit, $cursor);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(static::MAX_PER_PAGE)->description('How many messages to return, newest first. Pass null for the default of ['.static::PER_PAGE.'], at most ['.static::MAX_PER_PAGE.'].')->nullable()->required(),
            'before_message_id' => $schema->string()->description('Return only messages older than this one, using the next_before_message_id of a previous call. Pass null to start from the newest message.')->nullable()->required(),
            'scope' => $schema->string()->enum(['thread', 'channel'])->description('Where to read from: "thread" for the active thread, "channel" for top-level channel messages.')->required(),
        ];
    }

    /**
     * @param  iterable<int, Message>  $messages
     */
    protected function page(iterable $messages, int $limit, ?string $cursor): string
    {
        $page = LazyCollection::make($messages)->values()->all();

        if ($page === [] && $cursor !== null) {
            return 'No messages are older than that before_message_id. Either the history has no more messages, or that id did not come from a previous result.';
        }

        $next = count($page) > $limit ? $page[$limit - 1]->id() : null;
        $page = array_slice($page, 0, $limit);

        return implode("\n", [
            '<messages>',
            ...array_map(fn (Message $message): string => $message->toContext(), $page),
            '</messages>',
            ...($next !== null ? ["next_before_message_id: {$next}"] : []),
        ]);
    }
}
