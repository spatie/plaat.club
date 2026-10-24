<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Requests\Web\Concerns;

use Illuminate\Auth\AuthenticationException;
use Laravel\Converse\Bot;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Drivers\Web\WebDriver;
use Laravel\Converse\Exceptions\ShouldNotHappenException;
use Laravel\Converse\Thread;

trait InteractsWithWebChat
{
    public function authorize(BotRegistrar $registrar): bool
    {
        $bot = (string) $this->route('bot');

        abort_unless($registrar->has($bot), 404);

        $registered = $registrar->get($bot);

        abort_unless($registered->hasConnection($this->connectionName()), 404);

        return $this->owner() !== null;
    }

    public function bot(): Bot
    {
        return $this->container->make(BotRegistrar::class)->get((string) $this->route('bot'));
    }

    public function connectionName(): string
    {
        return (string) $this->route('connection');
    }

    public function threadId(): string
    {
        return (string) $this->route('thread');
    }

    public function participant(): ?object
    {
        return once(fn (): ?object => $this->container->make(BotRegistrar::class)->webUser($this, $this->driver()));
    }

    public function driver(): WebDriver
    {
        return $this->container->make(BotRegistrar::class)->webConnection((string) $this->route('bot'), $this->connectionName());
    }

    public function thread(): Thread
    {
        $owner = $this->owner() ?? throw new ShouldNotHappenException('A web turn reached the controller without an owner.');

        return $this->driver()->thread($owner, $this->threadId(), $this->bot()->name());
    }

    protected function owner(): ?string
    {
        return $this->driver()->owner($this, $this->participant(), $this->bot());
    }

    protected function failedAuthorization(): never
    {
        throw new AuthenticationException;
    }
}
