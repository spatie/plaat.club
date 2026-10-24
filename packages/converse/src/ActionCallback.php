<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Laravel\Converse\BotEvents\Action;

class ActionCallback
{
    public function __construct(
        protected string $url,
        protected ?string $value,
        protected Thread $channel,
        protected string $actionId,
        protected ?string $bot = null,
        protected bool $once = false,
    ) {}

    public function url(): string
    {
        return $this->url;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function channel(): Thread
    {
        return $this->channel;
    }

    public function actionId(): string
    {
        return $this->actionId;
    }

    public function bot(): ?string
    {
        return $this->bot;
    }

    public function isOnce(): bool
    {
        return $this->once;
    }

    public function matches(Action $action): bool
    {
        return $this->channel->encode() === $action->thread()->forChannel()->encode()
            && $this->actionId === $action->actionId()
            && ($this->bot === null || $this->bot === $action->thread()->bot());
    }
}
