<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Illuminate\Support\Collection;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

class ListUsersAction extends Action
{
    public function __construct(protected ListMessagesAction $listMessagesAction) {}

    /**
     * @return Collection<int, User>
     */
    public function handle(Thread $thread): Collection
    {
        return collect($this->listMessagesAction->handle($thread))
            ->reject(fn (Message $message): bool => $message->user()->isBot() || $message->user()->isAnonymous())
            ->map(fn (Message $message): User => $message->user())
            ->unique(fn (User $user): string => $user->id())
            ->values();
    }
}
