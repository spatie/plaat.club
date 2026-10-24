<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\Drivers\SupportsOpenDirectMessages;
use Laravel\Converse\Contracts\Drivers\SupportsSendEphemeralMessages;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Exceptions\UnsupportedCapabilityException;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class SendEphemeralMessageAction extends Action
{
    public function __construct(
        protected SendMessageAction $sendMessageAction,
        protected BotRegistrar $registrar,
    ) {}

    public function handle(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        $driver = $this->optionalDriverFor($thread, SupportsSendEphemeralMessages::class);
        $user = $thread->user();

        if ($user instanceof User && $driver instanceof SupportsSendEphemeralMessages) {
            return $this->send($thread, fn (): SentMessage => $driver->sendEphemeralMessage($thread, $content, $user));
        }

        if ($thread->isDirectMessage()) {
            return $this->sendMessageAction->handle($thread, $content);
        }

        if (! $user instanceof User) {
            throw new UnsupportedCapabilityException('Unable to send an ephemeral message without a recipient.');
        }

        $this->optionalDriverFor($thread, SupportsOpenDirectMessages::class)
            ?? throw UnsupportedCapabilityException::for($thread->connection(), Capability::SendEphemeralMessage, Capability::OpenDirectMessage);

        return $this->sendMessageAction->handle($thread, $content, $this->registrar->directMessage($thread->connection(), $user, $thread->bot()));
    }
}
