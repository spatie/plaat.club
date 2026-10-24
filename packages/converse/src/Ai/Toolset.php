<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Converse\Ai\Tools\AddReaction;
use Laravel\Converse\Ai\Tools\DeleteMessage;
use Laravel\Converse\Ai\Tools\DownloadAttachment;
use Laravel\Converse\Ai\Tools\EditCard;
use Laravel\Converse\Ai\Tools\EditMessage;
use Laravel\Converse\Ai\Tools\GetChannel;
use Laravel\Converse\Ai\Tools\GetUser;
use Laravel\Converse\Ai\Tools\ListMessages;
use Laravel\Converse\Ai\Tools\ListUsers;
use Laravel\Converse\Ai\Tools\RemoveReaction;
use Laravel\Converse\Ai\Tools\SendCard;
use Laravel\Converse\Ai\Tools\SendMessage;
use Laravel\Converse\Ai\Tools\Subscribe;
use Laravel\Converse\Ai\Tools\Unsubscribe;
use Laravel\Converse\Ai\Tools\UpdateStatus;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;

/**
 * @extends Collection<int, Tool>
 */
class Toolset extends Collection
{
    /** @var array<int, class-string<Tool>> */
    protected const array MESSAGING = [
        SendMessage::class,
        EditMessage::class,
        DeleteMessage::class,
        UpdateStatus::class,
    ];

    /** @var array<int, class-string<Tool>> */
    protected const array CARDS = [
        SendCard::class,
        EditCard::class,
    ];

    /** @var array<int, class-string<Tool>> */
    protected const array REACTIONS = [
        AddReaction::class,
        RemoveReaction::class,
    ];

    /** @var array<int, class-string<Tool>> */
    protected const array SUBSCRIPTIONS = [
        Subscribe::class,
        Unsubscribe::class,
    ];

    /** @var array<int, class-string<Tool>> */
    protected const array THREAD_CONTEXT = [
        ListMessages::class,
        ListUsers::class,
        GetUser::class,
        GetChannel::class,
        DownloadAttachment::class,
    ];

    public static function default(): static
    {
        return static::from([...static::THREAD_CONTEXT, ...static::REACTIONS, ...static::SUBSCRIPTIONS]);
    }

    public static function everything(): static
    {
        return static::from([...static::THREAD_CONTEXT, ...static::MESSAGING, ...static::CARDS, ...static::REACTIONS, ...static::SUBSCRIPTIONS]);
    }

    /**
     * @param  array<int, class-string<Tool>>  $tools
     */
    public static function from(array $tools): static
    {
        $thread = Container::getInstance()->make(ThreadContext::class)->thread();

        if (! $thread instanceof Thread) {
            return new static;
        }

        return new static(array_map(static fn (string $tool): Tool => new $tool, $tools))
            ->filter(static fn (Tool $tool): bool => static::registers($tool, $thread))
            ->map(static fn (Tool $tool): Tool => $tool instanceof Approvable ? $tool->withoutApproval() : $tool)
            ->values();
    }

    /** @internal */
    public static function registers(object $tool, Thread $thread): bool
    {
        return ! method_exists($tool, 'shouldRegister') || Container::getInstance()->call([$tool, 'shouldRegister'], [Thread::class => $thread]) === true;
    }

    public function cards(): static
    {
        return $this->restrictTo(static::CARDS);
    }

    public function messaging(): static
    {
        return $this->restrictTo(static::MESSAGING);
    }

    public function reactions(): static
    {
        return $this->restrictTo(static::REACTIONS);
    }

    /**
     * @param  class-string<Tool>  ...$tools
     */
    public function requireApproval(string ...$tools): static
    {
        return $this->map(fn (Tool $tool): Tool => $tool instanceof Approvable && ($tools === [] || in_array($tool::class, $tools, true))
            ? (clone $tool)->requireApproval()
            : $tool);
    }

    public function subscriptions(): static
    {
        return $this->restrictTo(static::SUBSCRIPTIONS);
    }

    public function threadContext(): static
    {
        return $this->restrictTo(static::THREAD_CONTEXT);
    }

    /**
     * @param  class-string<Tool>  ...$tools
     */
    public function without(string ...$tools): static
    {
        return $this->reject(fn (Tool $tool): bool => in_array($tool::class, $tools, true))->values();
    }

    /**
     * @param  array<int, class-string<Tool>>  $tools
     */
    protected function restrictTo(array $tools): static
    {
        return $this->filter(fn (Tool $tool): bool => in_array($tool::class, $tools, true))->values();
    }
}
