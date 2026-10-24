<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Closure;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Approval;
use Laravel\Converse\ApprovalTool;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Concurrency\ThreadCoordinator;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Exceptions\InvalidCardException;
use Laravel\Converse\Exceptions\SteeredException;
use Laravel\Converse\Exceptions\UnsupportedCapabilityException;
use Laravel\Converse\Markdown;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;
use Laravel\Converse\User;
use Throwable;

class AgentRunner
{
    public const string SILENT = '<converse:nothing-to-report/>';

    public const string REJECTED = 'That tool request was rejected, so it was not run.';

    public const string TOO_LARGE = 'That tool request is too large to review here, so it was not run.';

    protected const float TYPING_INTERVAL = 4.0;

    protected const float STEER_INTERVAL = 1.0;

    public function __construct(
        protected Container $container,
        protected ThreadCoordinator $coordinator,
        protected ConversationStore $conversations,
        protected ThreadContext $context,
        protected AgentResolver $agents,
    ) {}

    public function handle(AgentBot $bot, Message $message, Thread $thread): void
    {
        $messages = $message->batch();

        if (($delivery = $this->delivery($thread, $messages)) !== null) {
            if (trim($delivery['text']) !== '') {
                $thread->send(Markdown::make($delivery['text']));
            }

            $this->pause($message, $thread, $delivery['conversation'], collect($delivery['approvals'])->map(
                fn (array $tool): ApprovalTool => new ApprovalTool($tool['id'], $tool['name'], $tool['arguments'], $tool['reason']),
            ));

            $this->conversations->forgetMeta($thread, 'agent_delivery');

            return;
        }

        $prompt = $messages->implode(fn (Message $turn): string => $turn->toContext(), "\n\n");

        if ($message->isScheduled()) {
            $prompt .= "\n\n".$this->scheduledInstruction();
        }

        $paused = $this->turn(
            $bot,
            $message,
            $thread,
            $prompt,
            AgentAttachments::for($messages),
            interrupted: fn (): bool => $this->coordinator->isSteered($thread),
            onCompleted: $this->rememberDelivery($thread, $messages),
            scheduled: $message->isScheduled(),
        );

        if ($paused) {
            $this->conversations->putMeta($thread, 'agent_delivery', [
                ...(array) data_get($this->conversations->meta($thread), 'agent_delivery'),
                'approvals' => [],
            ]);
        }
    }

    public function handleAction(AgentBot $bot, Action $action, Thread $thread): void
    {
        $message = Message::make(
            $action->thread(),
            '',
            $action->user(),
            new DateTimeImmutable,
        )->withDirectMessage($action->isDirectMessage())
            ->withId($action->messageId())
            ->withEventId($action->eventId());

        $this->turn(
            $bot,
            $message,
            $thread,
            $action->toContext(),
            collect(),
            interrupted: fn (): bool => false,
            onCompleted: $this->rememberConversation($thread),
        );
    }

    public function resolveApprovalAction(AgentBot $bot, Action $action, Thread $thread): void
    {
        $this->decide($bot, $thread, $action->user(), (string) $action->value(), $action->actionId() === Approval::APPROVE_ACTION);
    }

    public function resolveApprovalReply(AgentBot $bot, Message $message, Thread $thread): bool
    {
        $approved = Approval::decisionFromReply($message->text());

        if ($approved === null || $message->isScheduled()) {
            return false;
        }

        $token = $this->conversations->pendingApproval($thread);

        if ($token === null) {
            return false;
        }

        $this->decide($bot, $thread, $message->user(), $token, $approved);

        return true;
    }

    public function isApprovalAction(Action $action): bool
    {
        return in_array($action->actionId(), [Approval::APPROVE_ACTION, Approval::REJECT_ACTION], true);
    }

    protected function notify(Thread $thread, string $notice): void
    {
        try {
            $thread->sendEphemeral($notice);
        } catch (UnsupportedCapabilityException) {
            $thread->send($notice);
        }
    }

    protected function decide(AgentBot $bot, Thread $thread, User $user, string $token, bool $approved): void
    {
        $continuation = $this->conversations->consumeApproval($thread, $token, $user->id());

        if (! $continuation instanceof ApprovalContinuation) {
            $this->notify($thread, $this->conversations->pendingApproval($thread) === $token
                ? 'Only the person who asked can answer this approval request.'
                : 'This approval request is invalid or has expired.');

            return;
        }

        $message = $continuation->message();
        $agent = $this->agentFor($bot, $message, $thread);

        if (! AgentResolver::remembers($agent)) {
            $this->notify($thread, 'This agent cannot resume approval requests.');

            return;
        }

        $decisions = $continuation->approval()->decisions($approved);

        $agent->continue($continuation->conversationId(), as: $this->conversations->agentParticipant($thread));

        $delivered = false;
        $remember = $this->rememberConversation($thread, $delivered);

        try {
            $completed = $this->whileTyping($thread, fn (): ?StreamedAgentResponse => $this->run(
                $agent,
                Decisions::from($decisions),
                collect(),
                $thread,
                interrupted: fn (): bool => false,
                onCompleted: $remember,
                scheduled: $message->isScheduled(),
            ));
        } catch (Throwable $throwable) {
            $this->conversations->putApproval($thread, $token, $continuation, $this->approvalSeconds());

            throw $throwable;
        }

        $thread->resolveApproval($continuation->approval(), $continuation->card(), $decisions);

        if (! $delivered && ! $approved) {
            $this->acknowledgeRejection($thread, $completed);
        }

        $this->requestApproval($message, $thread, $completed);
    }

    /**
     * @param  Collection<int, File>  $attachments
     * @param  Closure(): bool  $interrupted
     * @param  Closure(StreamedAgentResponse, string): void  $onCompleted
     */
    protected function turn(AgentBot $bot, Message $message, Thread $thread, string $prompt, Collection $attachments, Closure $interrupted, Closure $onCompleted, bool $scheduled = false): bool
    {
        $completed = $this->whileTyping($thread, function () use ($bot, $message, $thread, $prompt, $attachments, $interrupted, $onCompleted, $scheduled): ?StreamedAgentResponse {
            $agent = $this->agentFor($bot, $message, $thread);

            $this->resume($agent, $thread);

            return $this->run($agent, $prompt, $attachments, $thread, $interrupted, $onCompleted, $scheduled);
        });

        return $this->requestApproval($message, $thread, $completed);
    }

    protected function resume(Agent $agent, Thread $thread): void
    {
        if (! AgentResolver::remembers($agent)) {
            return;
        }

        $agent->continueOrStart(
            $this->conversations->agentConversation($thread),
            $this->conversations->agentParticipant($thread),
        );
    }

    protected function agentFor(AgentBot $bot, Message $message, Thread $thread): Agent
    {
        return $this->agents->resolve($bot, $message, $thread);
    }

    /**
     * @param  iterable<int, mixed>  $stream
     * @param  Closure(): bool  $interrupted
     */
    protected function consume(iterable $stream, Thread $thread, Closure $interrupted): string
    {
        $adapter = new StreamAdapter;
        $text = '';
        $checkedAt = Date::now()->getPreciseTimestamp(3) / 1000;
        $typedAt = null;

        foreach ($stream as $event) {
            if ($event instanceof ToolCall && ($typedAt === null || Date::now()->getPreciseTimestamp(3) / 1000 - $typedAt >= static::TYPING_INTERVAL)) {
                $typedAt = Date::now()->getPreciseTimestamp(3) / 1000;

                $thread->updateStatus("Running {$event->toolCall->name}...");
            }

            $text .= $adapter->text($event);

            if (Date::now()->getPreciseTimestamp(3) / 1000 - $checkedAt >= static::STEER_INTERVAL) {
                $checkedAt = Date::now()->getPreciseTimestamp(3) / 1000;

                if ($interrupted()) {
                    throw new SteeredException;
                }
            }
        }

        return $text;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array{text: string, conversation: ?string, approvals: array<int, array{id: string, name: string, arguments: array<string, mixed>, reason: ?string}>}|null
     */
    protected function delivery(Thread $thread, Collection $messages): ?array
    {
        $delivery = data_get($this->conversations->meta($thread), 'agent_delivery');

        if (! is_array($delivery)
            || ! is_array($delivery['messages'] ?? null)
            || ! is_string($delivery['text'] ?? null)
            || $delivery['messages'] !== $this->messageKeys($messages)) {
            return null;
        }

        /** @var array<int, array{id: string, name: string, arguments: array<string, mixed>, reason: ?string}> $approvals */
        $approvals = is_array($delivery['approvals'] ?? null) ? $delivery['approvals'] : [];

        return [
            'text' => $delivery['text'],
            'conversation' => is_string($delivery['conversation'] ?? null) ? $delivery['conversation'] : null,
            'approvals' => $approvals,
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, string>
     */
    protected function messageKeys(Collection $messages): array
    {
        return $messages
            ->map(fn (Message $message): string => $message->id() ?? "storage:{$message->storageId()}")
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Closure(StreamedAgentResponse, string): void
     */
    protected function rememberConversation(Thread $thread, ?bool &$delivered = null): Closure
    {
        return function (StreamedAgentResponse $response, string $text) use ($thread, &$delivered): void {
            $delivered = trim($text) !== '';

            if ($response->conversationId !== null) {
                $this->conversations->putAgentConversation($thread, $response->conversationId);
            }
        };
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Closure(StreamedAgentResponse, string): void
     */
    protected function rememberDelivery(Thread $thread, Collection $messages): Closure
    {
        $remember = $this->rememberConversation($thread);

        return function (StreamedAgentResponse $response, string $text) use ($remember, $thread, $messages): void {
            $remember($response, $text);

            if (trim($text) !== '' || $response->pendingApprovals->isNotEmpty()) {
                $this->conversations->putMeta($thread, 'agent_delivery', [
                    'messages' => $this->messageKeys($messages),
                    'text' => $text,
                    'conversation' => $response->conversationId,
                    'approvals' => $this->approvalTools($response)->toArray(),
                ]);
            }
        };
    }

    protected function acknowledgeRejection(Thread $thread, ?StreamedAgentResponse $completed): void
    {
        if ($completed instanceof StreamedAgentResponse && $completed->pendingApprovals->isNotEmpty()) {
            return;
        }

        $thread->send(static::REJECTED);
    }

    protected function requestApproval(Message $message, Thread $thread, ?StreamedAgentResponse $response): bool
    {
        return $response instanceof StreamedAgentResponse
            && $this->pause($message, $thread, $response->conversationId, $this->approvalTools($response));
    }

    /**
     * @return Collection<int, ApprovalTool>
     */
    protected function approvalTools(StreamedAgentResponse $response): Collection
    {
        return $response->pendingApprovals->map(fn (PendingApproval $pending): ApprovalTool => new ApprovalTool(
            $pending->id,
            $pending->tool,
            $pending->arguments,
            $pending->reason,
        ))->values();
    }

    /**
     * @param  Collection<int, ApprovalTool>  $tools
     */
    protected function pause(Message $message, Thread $thread, ?string $conversationId, Collection $tools): bool
    {
        if ($tools->isEmpty()) {
            return false;
        }

        if ($conversationId === null) {
            $thread->send('Tool approval requires durable Laravel AI memory.');

            return false;
        }

        $token = Str::random(40);

        $approval = new Approval($token, $tools);

        try {
            $card = $thread->requestApproval($approval);
        } catch (InvalidCardException) {
            $thread->send(static::TOO_LARGE);

            return false;
        }

        $this->conversations->putApproval($thread, $token, new ApprovalContinuation(
            $message->isScheduled() ? null : $message->user()->id(),
            $message,
            $conversationId,
            $approval,
            $card,
        ), $this->approvalSeconds());

        return true;
    }

    protected function approvalSeconds(): int
    {
        return max(1, (int) config('converse.processing.approval_ttl'));
    }

    /**
     * @param  Collection<int, File>  $attachments
     * @param  Closure(): bool  $interrupted
     * @param  Closure(StreamedAgentResponse, string): void  $onCompleted
     */
    protected function run(Agent $agent, mixed $prompt, Collection $attachments, Thread $thread, Closure $interrupted, Closure $onCompleted, bool $scheduled = false): ?StreamedAgentResponse
    {
        return $this->context->for($thread, function () use ($agent, $prompt, $attachments, $thread, $interrupted, $onCompleted, $scheduled): ?StreamedAgentResponse {
            $completed = null;

            $text = $this->consume(
                $agent->stream($prompt, $attachments->all())->then(
                    function (StreamedAgentResponse $response) use (&$completed): void {
                        $completed = $response;
                    },
                ),
                $thread,
                $interrupted,
            );

            if ($scheduled) {
                $text = $this->withoutSilence($text);
            }

            if ($interrupted()) {
                if ($completed instanceof StreamedAgentResponse) {
                    $onCompleted($completed, '');
                }

                throw new SteeredException;
            }

            if ($completed instanceof StreamedAgentResponse) {
                $onCompleted($completed, $text);
            }

            if (trim($text) !== '') {
                $thread->send(Markdown::make($text));
            }

            return $completed;
        });
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function whileTyping(Thread $thread, Closure $callback): mixed
    {
        $thread->updateStatus();

        try {
            return $callback();
        } finally {
            $thread->clearStatus();
        }
    }

    protected function scheduledInstruction(): string
    {
        return implode("\n", [
            '<scheduled_turn>',
            'A schedule started this turn, so nobody is waiting on an answer.',
            'When the prompt makes delivery conditional and there is nothing to report, reply with exactly '.static::SILENT.' and nothing else. That reply is never delivered.',
            '</scheduled_turn>',
        ]);
    }

    protected function withoutSilence(string $text): string
    {
        return trim($text) === static::SILENT ? '' : $text;
    }
}
