<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents;

use Laravel\Converse\BotEvents\Concerns\InteractsWithEvent;
use Laravel\Converse\BotEvents\Concerns\NeutralizesContext;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class Action implements BotEvent
{
    use InteractsWithEvent;
    use NeutralizesContext;

    /** @param  array<string, mixed>  $raw */
    public function __construct(
        protected Thread $thread,
        protected string $actionId,
        protected User $user,
        protected ?string $value = null,
        protected ?string $messageId = null,
        protected ?string $eventId = null,
        protected array $raw = [],
        protected bool $isDirectMessage = false,
    ) {}

    public function actionId(): string
    {
        return $this->actionId;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function withValue(?string $value): static
    {
        $action = clone $this;
        $action->value = $value;

        return $action;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    /** @internal */
    public function toContext(): string
    {
        return implode("\n", [
            '<action note="The user clicked a control on a card this bot sent.">',
            'action_id: '.$this->neutralize($this->actionId),
            ...($this->value !== null ? ['value: '.$this->neutralize($this->value)] : []),
            ...($this->messageId !== null ? ["message_id: {$this->messageId}"] : []),
            '</action>',
        ]);
    }
}
