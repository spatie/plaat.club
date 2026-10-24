<?php

declare(strict_types=1);

namespace Laravel\Converse\Routing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Converse\ActionCallback;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Ai\AgentRunner;
use Laravel\Converse\Bot;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Contracts\Drivers\TreatsDirectMessagesAsMentions;
use Laravel\Converse\Enums\RoutingSlot;
use Laravel\Converse\Jobs\DeliverActionCallback;
use Laravel\Converse\Thread;
use Ramsey\Uuid\Uuid;

class EventRouter
{
    public function __construct(protected Container $container, protected AgentRunner $agents, protected CallbackRegistry $callbacks) {}

    /**
     * @param  Collection<int, Message>  $others
     */
    public function route(Bot $bot, BotEvent $event, Thread $thread, Collection $others = new Collection): void
    {
        $event = $event->withThread($thread);

        if ($event->user()->isSelf()) {
            return;
        }

        new Pipeline($this->container)
            ->send($event)
            ->through($bot->middlewares())
            ->then(fn (BotEvent $event) => $this->dispatch($bot, $event, $thread, $others));
    }

    /** @internal */
    public function throughMiddleware(Bot $bot, BotEvent $event): ?BotEvent
    {
        $passed = new Pipeline($this->container)
            ->send($event)
            ->through($bot->middlewares())
            ->then(fn (BotEvent $event): BotEvent => $event);

        return $passed instanceof BotEvent ? $passed : null;
    }

    /**
     * @param  Collection<int, Message>  $others
     */
    protected function dispatch(Bot $bot, BotEvent $event, Thread $thread, Collection $others): void
    {
        if ($event instanceof Action && ($callback = $this->callbacks->resolve($event)) instanceof ActionCallback) {
            $event = $event->withValue($callback->value());
            DeliverActionCallback::dispatch($callback->url(), $event, $this->deliveryId($event));

            return;
        }

        if ($event instanceof Message) {
            $event = $event->withOthers($others);
        }

        if ($bot instanceof AgentBot && $this->resolvesApproval($bot, $event, $thread)) {
            return;
        }

        $handler = $event instanceof Message
            ? $this->matchMessage($bot, $event, $thread)
            : $bot->handlerFor($event::class);

        if ($handler === null && $bot instanceof AgentBot && $this->shouldRunAgent($bot, $event, $thread)) {
            if ($event instanceof Message && $event->isMention() && ! $event->isScheduled() && $bot->subscribesToMentions()) {
                $thread->subscribe();
            }

            if ($event instanceof Action) {
                $this->agents->handleAction($bot, $event, $thread);
            } else {
                $this->agents->handle($bot, $event, $thread);
            }

            return;
        }

        $handler ??= $bot->handlerFor(RoutingSlot::Fallback);

        if ($handler !== null) {
            $this->invoke($bot, $handler, $event);
        }
    }

    protected function deliveryId(Action $event): string
    {
        return $event->eventId() === null
            ? (string) Str::uuid()
            : Uuid::uuid5(Uuid::NAMESPACE_URL, implode(':', [$event->thread()->encode(), $event->actionId(), $event->eventId()]))->toString();
    }

    /** @param  array{class-string, string}  $handler */
    protected function invoke(Bot $bot, array $handler, BotEvent $event): void
    {
        $this->container->make($handler[0], ['bot' => $bot])->{$handler[1]}($event);
    }

    /** @internal */
    public function answers(Bot $bot, Message $message, Thread $thread): bool
    {
        return $this->matchMessage($bot, $message, $thread) !== null
            || ($bot instanceof AgentBot && $bot->isAddressedBy($message, $thread))
            || $bot->handlerFor(RoutingSlot::Fallback) !== null;
    }

    protected function resolvesApproval(AgentBot $bot, BotEvent $event, Thread $thread): bool
    {
        if ($event instanceof Action && $this->agents->isApprovalAction($event)) {
            $this->agents->resolveApprovalAction($bot, $event, $thread);

            return true;
        }

        return $event instanceof Message && $this->agents->resolveApprovalReply($bot, $event, $thread);
    }

    /** @phpstan-assert-if-true Action|Message $event */
    protected function shouldRunAgent(AgentBot $bot, BotEvent $event, Thread $thread): bool
    {
        return $event instanceof Action
            || ($event instanceof Message && $bot->isAddressedBy($event, $thread));
    }

    /**
     * @internal
     *
     * @return array{class-string, string}|null
     */
    public function matchMessage(Bot $bot, Message $message, Thread $thread): ?array
    {
        if ($message->isScheduled()) {
            return null;
        }

        if ($message->isDirectMessage() && ($handler = $bot->handlerFor(RoutingSlot::DirectMessage)) !== null) {
            return $handler;
        }

        if (($handler = $bot->handlerFor(RoutingSlot::SubscribedMessage)) !== null && $thread->isSubscribed()) {
            return $handler;
        }

        if ($message->isMention() && ($handler = $bot->handlerFor(RoutingSlot::Mention)) !== null) {
            return $handler;
        }

        return $bot->handlerFor($message::class) ?? $this->mentionHandlerForDirectMessage($bot, $message, $thread);
    }

    /**
     * @return array{class-string, string}|null
     */
    protected function mentionHandlerForDirectMessage(Bot $bot, Message $message, Thread $thread): ?array
    {
        if (! $message->isDirectMessage() || ! $thread->driver() instanceof TreatsDirectMessagesAsMentions) {
            return null;
        }

        return $bot->handlerFor(RoutingSlot::Mention);
    }
}
