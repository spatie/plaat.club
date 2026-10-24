<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Support\Str;
use Laravel\Ai\AgentUserInteraction\Chat;
use Laravel\Converse\Ai\AgentTurn;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Contracts\Turn;
use Laravel\Converse\Enums\RunStatus;
use Laravel\Converse\Enums\WebExecution;
use Laravel\Converse\Exceptions\DuplicateMessageException;
use Laravel\Converse\Jobs\RunWebTurn;
use Laravel\Converse\Runs\HeldRunEvents;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\Runs\TurnRunner;
use Laravel\Converse\Thread;
use Throwable;

class WebTurnDispatcher
{
    public function __construct(
        protected RunStore $runs,
        protected TurnRunner $turns,
        protected WebTurnFactory $factory,
        protected ConversationStore $conversations,
    ) {}

    /**
     * @param  array<string, mixed>  $chatInput
     *
     * @throws DuplicateMessageException
     */
    public function dispatch(
        Bot $bot,
        Message|Command $event,
        Thread $thread,
        Chat $input,
        array $chatInput,
        ?object $participant,
        string $runId,
        WebChatOptions $options,
        HeldRunEvents $held = new HeldRunEvents,
    ): int {
        try {
            $turn = $this->factory->make(
                bot: $bot,
                event: $event,
                thread: $thread,
                input: $input,
                participant: $participant,
                runId: $runId,
            );

            $prompt = $this->turns->recordPrompt($turn, $runId);

            if ($held->events() !== []) {
                $this->runs->append($runId, $held->events());
            }

            $held->storeReplies($this->conversations);

            $conversation = $this->agentConversation($turn);

            dispatch(new RunWebTurn(
                thread: $thread,
                event: $event,
                runId: $runId,
                input: $chatInput,
                participant: $participant,
                timeout: $options->runTimeoutSeconds(),
                conversationId: $conversation['conversationId'],
                userMessageId: $conversation['userMessageId'],
            ))->afterResponse($options->execution() === WebExecution::AfterResponse);
        } catch (DuplicateMessageException $exception) {
            $this->runs->finish($runId, RunStatus::Failed);

            throw $exception;
        } catch (Throwable $throwable) {
            $this->turns->abandon($runId);

            throw $throwable;
        }

        return $prompt->offset();
    }

    public function answer(Message $event, string $runId, HeldRunEvents $held): int
    {
        $threadId = $event->thread()->id();
        $messageId = $event->id() ?? (string) Str::uuid7();
        $prompt = $event->text() === '' ? [] : TurnEvents::message($messageId, 'user', $event->text(), clientMessageId: $event->id());

        try {
            $range = $this->runs->appendPrompt($runId, $messageId, [
                ...$prompt,
                TurnEvents::runStarted($threadId, $runId),
                ...$held->events(),
                TurnEvents::runFinished($threadId, $runId),
            ]);

            if ($prompt !== []) {
                $this->storeUserMessage($event);
            }

            $held->storeReplies($this->conversations);

            $this->runs->finish($runId, RunStatus::Completed);
        } catch (Throwable $throwable) {
            $this->turns->abandon($runId);

            throw $throwable;
        }

        return $range->offset();
    }

    protected function storeUserMessage(Message $message): void
    {
        $this->conversations->queueUserMessage($message);

        $this->conversations->pendingMessages($message->thread())
            ->filter(fn (Message $pending): bool => $pending->id() === $message->id())
            ->each(fn (Message $pending) => $this->conversations->completeUserMessage($pending));
    }

    /**
     * @return array{conversationId: ?string, userMessageId: ?string}
     */
    protected function agentConversation(Turn $turn): array
    {
        if (! $turn instanceof AgentTurn) {
            return ['conversationId' => null, 'userMessageId' => null];
        }

        return ['conversationId' => $turn->conversationId(), 'userMessageId' => $turn->userMessageId()];
    }
}
