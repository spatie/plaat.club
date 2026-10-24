<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Laravel\Converse\Attachment;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\Drivers\Driver;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Exceptions\UnsupportedCapabilityException;
use Laravel\Converse\Markdown;
use Laravel\Converse\Runs\HeldRunEvents;
use Laravel\Converse\Runs\RunWriters;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;

abstract class Action
{
    /**
     * @template TDriver of Driver
     *
     * @param  class-string<TDriver>  $contract
     * @return TDriver
     */
    protected function driverFor(Thread $thread, string $contract): Driver
    {
        return $this->optionalDriverFor($thread, $contract)
            ?? throw UnsupportedCapabilityException::for($thread->connection(), Capability::tryFromContract($contract) ?? $contract);
    }

    /**
     * @template TDriver of Driver
     *
     * @param  class-string<TDriver>  $contract
     * @return TDriver|null
     */
    protected function optionalDriverFor(Thread $thread, string $contract): ?Driver
    {
        return $thread->driver() instanceof $contract ? $thread->driver() : null;
    }

    /**
     * @param  Closure(): SentMessage  $send
     * @param  Collection<int, Attachment>  $attachments
     */
    protected function deliver(Thread $thread, Closure $send, string $text, Collection $attachments = new Collection): SentMessage
    {
        $sent = $this->send($thread, $send);

        $writer = Container::getInstance()->make(RunWriters::class)->for($sent->thread());

        if ($writer instanceof HeldRunEvents) {
            $writer->holdReply($sent, $text, $attachments);

            return $sent;
        }

        Container::getInstance()->make(ConversationStore::class)->storeBotMessage($sent, $text, $attachments);

        return $sent;
    }

    /**
     * @param  Closure(): SentMessage  $send
     */
    protected function send(Thread $thread, Closure $send): SentMessage
    {
        $sent = Container::getInstance()->make(ThreadContext::class)->for($thread, $send);

        return $sent->withThread($sent->thread()->withBot($thread->bot()));
    }

    protected function textFor(Card|Markdown|string $content): string
    {
        return $content instanceof Card ? $content->fallbackText() : (string) $content;
    }
}
