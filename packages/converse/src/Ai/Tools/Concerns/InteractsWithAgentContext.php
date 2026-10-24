<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools\Concerns;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Exceptions\ShouldNotHappenException;
use Laravel\Converse\Exceptions\UnsupportedCapabilityException;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;
use Laravel\Converse\User;

trait InteractsWithAgentContext
{
    protected function thread(): Thread
    {
        return Container::getInstance()->make(ThreadContext::class)->thread()
            ?? throw new ShouldNotHappenException('Converse tools may only be used while handling a Converse agent event.');
    }

    protected function notSent(string $verb): string
    {
        return "That message was not posted by this bot, so it cannot be {$verb}.";
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(User $user): array
    {
        return Arr::except($user->toArray(), 'is_self');
    }

    protected function unsupported(Capability $capability): string
    {
        return UnsupportedCapabilityException::for($this->thread()->connection(), $capability)->getMessage();
    }
}
