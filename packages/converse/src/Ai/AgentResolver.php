<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Closure;
use Illuminate\Container\Container;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore as AiConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Storage\StoredMessage;
use Laravel\Ai\Tools\ToolNameResolver;
use Laravel\Converse\AgentBot;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Exceptions\AgentException;
use Laravel\Converse\Exceptions\InvalidBotException;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;

class AgentResolver
{
    public function __construct(
        protected Container $container,
        protected ThreadContext $context,
    ) {}

    public function resolve(AgentBot $bot, Message|Command $event, Thread $thread, ?object $participant = null): Agent
    {
        $resolver = $bot->agentResolver();

        $agent = $this->context->for($thread, fn (): mixed => $resolver instanceof Closure
            ? $this->container->call($resolver, ['message' => $event, 'thread' => $thread, 'participant' => $participant])
            : $this->container->make($resolver));

        if (! $agent instanceof Agent) {
            throw new AgentException("Bot [{$bot->name()}] did not resolve a Laravel AI agent.");
        }

        return $this->withConverseTools($agent, $bot);
    }

    /**
     * @phpstan-assert RemembersConversations $agent
     */
    public static function assertRemembersOnWeb(string $bot, Agent $agent): void
    {
        if (static::remembers($agent)) {
            return;
        }

        $class = $agent::class;

        throw new InvalidBotException(
            "Bot [{$bot}] cannot bind agent [{$class}] to a web connection. Web agents must remember their conversation, so implement ["
            .RemembersConversations::class.'] or use the ['.RemembersConversationsTrait::class.'] trait.',
        );
    }

    /**
     * @phpstan-assert-if-true RemembersConversations $agent
     */
    public static function remembers(Agent $agent): bool
    {
        return RememberConversation::appliesTo($agent);
    }

    public static function newestStoredMessage(AiConversationStore $store, string $conversationId): ?StoredMessage
    {
        if (! $store instanceof PaginatesConversations) {
            return null;
        }

        return collect($store->paginateConversationMessages($conversationId, 1)->items())->first();
    }

    protected function withConverseTools(Agent $agent, AgentBot $bot): Agent
    {
        if (! method_exists($agent, 'withTools')) {
            return $agent;
        }

        $name = $bot->name();

        $agent->withTools(static function (array $declared) use ($name): array {
            $names = array_map(
                ToolNameResolver::resolve(...),
                array_filter($declared, static fn (object $tool): bool => $tool instanceof Tool),
            );

            $thread = Container::getInstance()->make(ThreadContext::class)->thread();

            $declared = array_values(array_filter(
                $declared,
                static fn (object $tool): bool => ! $thread instanceof Thread || Toolset::registers($tool, $thread),
            ));

            /** @var AgentBot $bot */
            $bot = Container::getInstance()->make(BotRegistrar::class)->get($name);

            $toolset = $bot->tools();

            return [
                ...$declared,
                ...$toolset->reject(static fn (Tool $tool): bool => in_array(ToolNameResolver::resolve($tool), $names)),
            ];
        });

        return $agent;
    }
}
