<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Converse\BotEvents\Command;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Drivers\Web\WebTranscript;
use Laravel\Converse\Drivers\Web\WebTurnDispatcher;
use Laravel\Converse\Drivers\Web\WebTurnFactory;
use Laravel\Converse\Exceptions\DuplicateMessageException;
use Laravel\Converse\Http\Controllers\Web\Concerns\RespondsWithoutCaching;
use Laravel\Converse\Http\Requests\Web\WebChatRequest;
use Laravel\Converse\Routing\EventRouter;
use Laravel\Converse\Runs\HeldRunEvents;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\RunState;
use Laravel\Converse\Thread;

class WebChatController
{
    use RespondsWithoutCaching;

    public function __construct(
        protected RunStore $runs,
        protected WebTurnFactory $factory,
        protected WebTurnDispatcher $dispatcher,
        protected EventRouter $router,
        protected WebTranscript $transcript,
        protected RunWriters $writers,
    ) {}

    public function __invoke(WebChatRequest $request): JsonResponse
    {
        $bot = $request->bot();
        $thread = $request->thread();
        $options = $request->driver()->options();

        $event = $request->driver()->event(
            bot: $bot,
            thread: $thread,
            input: $request->chat(),
            user: $request->participant(),
            messageId: $request->messageId(),
        );

        if (! $this->factory->answers($bot, $event, $thread, $request->chat())) {
            return $this->noStore(['message' => 'Nothing on this bot answers that message.'], 422);
        }

        $chatInput = $request->chatInput();
        $held = new HeldRunEvents;
        $dropped = false;

        if ($this->factory->runsAgent($bot, $event, $thread, $request->chat())) {
            $passed = $this->writers->writingTo($thread, $held, fn (): ?BotEvent => $this->router->throughMiddleware($bot, $event));

            if (! $passed instanceof BotEvent && $held->events() === []) {
                return $this->noStore(['message' => 'This bot does not accept that message.'], 422);
            }

            $dropped = ! $passed instanceof BotEvent;

            if ($passed instanceof Message && $request->chat()->message() instanceof UserMessage && $passed->text() !== $event->text()) {
                $chatInput = $this->withText($chatInput, $passed->text());
            }

            if ($passed instanceof Message) {
                $event = $passed;
            }
        }

        $options->assertQueueOutlastsTurn();

        $live = $this->runs->liveRun($thread);

        if ($live instanceof RunState) {
            return $this->busy($request, $live);
        }

        if ($this->alreadyAnswered($request)) {
            return $this->duplicate($request);
        }

        if (! $this->approvalsMatch($request)) {
            return $this->approvalMismatch($thread);
        }

        if (! $this->runs->claim($thread, $request->runId(), $options->expirySeconds(), $request->messageId())) {
            return $this->busy($request, $this->runs->liveRun($thread));
        }

        if ($dropped && $event instanceof Message) {
            return $this->accepted($request, $this->dispatcher->answer($event, $request->runId(), $held));
        }

        return $this->accept($request, $event, $chatInput, $held);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function withText(array $input, string $text): array
    {
        $messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
        $last = array_key_last($messages) ?? 0;
        $content = $messages[$last]['content'] ?? null;

        $messages[$last]['content'] = is_array($content) ? $this->withTextPart($content, $text) : $text;

        return [...$input, 'messages' => $messages];
    }

    /**
     * @param  array<int|string, mixed>  $parts
     * @return list<mixed>
     */
    protected function withTextPart(array $parts, string $text): array
    {
        $replaced = false;
        $rewritten = [];

        foreach ($parts as $part) {
            if (! is_array($part) || ($part['type'] ?? null) !== 'text') {
                $rewritten[] = $part;
            } elseif (! $replaced) {
                $rewritten[] = [...$part, 'text' => $text];
                $replaced = true;
            }
        }

        return $replaced ? $rewritten : [['type' => 'text', 'text' => $text], ...$rewritten];
    }

    protected function alreadyAnswered(WebChatRequest $request): bool
    {
        $messageId = $request->messageId();

        if ($messageId === null) {
            return false;
        }

        return $this->runs->hasAnswered($request->thread(), $messageId);
    }

    protected function approvalsMatch(WebChatRequest $request): bool
    {
        $decisions = $request->chat()->decisions();

        if (! $decisions instanceof Decisions) {
            return true;
        }

        return $this->transcript->approvalsMatch($request->thread(), $decisions);
    }

    /**
     * @param  array<string, mixed>  $chatInput
     */
    protected function accept(WebChatRequest $request, Message|Command $event, array $chatInput, HeldRunEvents $held): JsonResponse
    {
        try {
            $offset = $this->dispatcher->dispatch(
                bot: $request->bot(),
                event: $event,
                thread: $request->thread(),
                input: AgentUserInteraction::chat($chatInput),
                chatInput: $chatInput,
                participant: $request->participant(),
                runId: $request->runId(),
                options: $request->driver()->options(),
                held: $held,
            );
        } catch (DuplicateMessageException) {
            return $this->duplicate($request);
        }

        return $this->accepted($request, $offset);
    }

    protected function accepted(WebChatRequest $request, int $offset): JsonResponse
    {
        return $this->noStore([
            'threadId' => $request->threadId(),
            'runId' => $request->runId(),
            'offset' => $offset,
        ], 202);
    }

    protected function approvalMismatch(Thread $thread): JsonResponse
    {
        return $this->noStore([
            'message' => 'Approval decisions do not match the pending tool calls.',
            'code' => 'approval_mismatch',
            'approvals' => collect($this->transcript->pendingApprovals($thread) ?? [])->toArray(),
        ], 409);
    }

    protected function duplicate(WebChatRequest $request): JsonResponse
    {
        return $this->noStore([
            'message' => 'This message was already sent.',
            'threadId' => $request->threadId(),
            'runId' => null,
            'offset' => $this->runs->lastEventSequence($request->thread()),
        ], 409);
    }

    protected function busy(WebChatRequest $request, ?RunState $live): JsonResponse
    {
        return $this->noStore([
            'message' => 'This chat thread is already answering.',
            'threadId' => $request->threadId(),
            'runId' => $live?->id(),
            'offset' => $live?->offset() ?? $this->runs->lastEventSequence($request->thread()),
        ], 409);
    }
}
