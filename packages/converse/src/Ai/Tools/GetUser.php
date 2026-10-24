<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

#[Strict]
class GetUser implements Tool
{
    use InteractsWithAgentContext;

    public function description(): string
    {
        return 'Get the profile of a user, completing available details from their platform profile. Only users seen in the active thread can be looked up. Omit user_id for the sender whose message is driving the current turn.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return ! $thread->isDirectMessage()
            || ($thread->user() instanceof User && ! $thread->user()->isAnonymous())
            || $thread->supports(Capability::GetUser);
    }

    public function handle(Request $request): string
    {
        $request->validate(['user_id' => ['nullable', 'string', 'max:255']]);

        $thread = $this->thread();
        $id = $request->string('user_id')->value();

        if ($id !== '') {
            $user = $this->participant($thread, $id);

            if (! $user instanceof User) {
                return "User [{$id}] has not been seen in the active thread.";
            }

            return json_encode($this->profile($thread->getUser($user) ?? $user), JSON_THROW_ON_ERROR);
        }

        $user = $thread->user();

        if (! $user instanceof User || $user->isAnonymous()) {
            return 'The active thread has no known sender.';
        }

        return json_encode($this->profile($thread->getUser($user) ?? $user), JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()->description('The ID of a user seen in the active thread. Never invent an ID. Pass null for the sender of the message driving this turn.')->nullable()->required(),
        ];
    }

    protected function participant(Thread $thread, string $id): ?User
    {
        if ($thread->user() instanceof User && $thread->user()->id() === $id) {
            return $thread->user();
        }

        return $thread->users()->first(fn (User $user): bool => $user->id() === $id);
    }
}
