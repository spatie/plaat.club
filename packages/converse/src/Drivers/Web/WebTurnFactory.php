<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Laravel\Ai\AgentUserInteraction\Chat;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Ai\AgentResolver;
use Laravel\Converse\Ai\AgentTurn;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Turn;
use Laravel\Converse\Routing\EventRouter;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;

class WebTurnFactory
{
    public function __construct(
        protected AgentResolver $agents,
        protected RunWriters $writers,
        protected ThreadContext $context,
        protected EventRouter $router,
        protected ConversationStore $conversations,
    ) {}

    /** @internal */
    public function make(
        Bot $bot,
        Message|Command $event,
        Thread $thread,
        Chat $input,
        ?object $participant,
        string $runId,
        ?string $conversationId = null,
        ?string $userMessageId = null,
    ): Turn {
        if (! $this->runsAgent($bot, $event, $thread, $input)) {
            return new HandlerTurn(
                bot: $bot,
                event: $event,
                runId: $runId,
                writers: $this->writers,
                context: $this->context,
                router: $this->router,
                conversations: $this->conversations,
            );
        }

        return new AgentTurn(
            agent: $this->agent($bot, $event, $thread, $participant),
            input: $input,
            thread: $thread,
            runId: $runId,
            participant: $participant ?? $this->conversations->agentParticipant($thread),
            writers: $this->writers,
            context: $this->context,
            conversationId: $conversationId,
            userMessageId: $userMessageId,
            clientMessageId: $event instanceof Message ? $event->id() : null,
        );
    }

    /** @internal */
    public function answers(Bot $bot, Message|Command $event, Thread $thread, Chat $input): bool
    {
        if ($input->decisions() instanceof Decisions) {
            return $bot instanceof AgentBot;
        }

        if ($event instanceof Command) {
            return true;
        }

        return $this->router->answers($bot, $event, $thread);
    }

    /**
     * @internal
     *
     * @phpstan-assert-if-true AgentBot $bot
     */
    public function runsAgent(Bot $bot, Message|Command $event, Thread $thread, Chat $input): bool
    {
        return $bot instanceof AgentBot && ! $this->runsOnHandler($bot, $event, $thread, $input);
    }

    protected function runsOnHandler(AgentBot $bot, Message|Command $event, Thread $thread, Chat $input): bool
    {
        if ($event instanceof Command) {
            return true;
        }

        if ($input->decisions() instanceof Decisions) {
            return false;
        }

        return $this->router->matchMessage($bot, $event, $thread) !== null;
    }

    /**
     * @return Agent&RemembersConversations
     */
    protected function agent(AgentBot $bot, Message|Command $event, Thread $thread, ?object $participant): Agent
    {
        $agent = $this->agents->resolve($bot, $event, $thread, $participant);

        AgentResolver::assertRemembersOnWeb($bot->name(), $agent);

        return $agent;
    }
}
