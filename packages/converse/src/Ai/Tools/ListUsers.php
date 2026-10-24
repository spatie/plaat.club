<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

#[Strict]
class ListUsers implements Tool
{
    use InteractsWithAgentContext;

    protected const int MAX_PROFILES = 25;

    public function description(): string
    {
        return 'List the non-bot users observed in the active thread, completing available details from their platform profiles. This is who has spoken here, most recent speaker first, not the full channel roster, and it is capped at the most recent speakers. Use [GetUser] for one user in more detail. Always scoped to the active thread.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return ! $thread->isDirectMessage();
    }

    public function handle(Request $request): string
    {
        $thread = $this->thread();

        return $thread->users()
            ->take(static::MAX_PROFILES)
            ->map(fn (User $user): array => $this->profile($thread->getUser($user) ?? $user))
            ->toJson(JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
