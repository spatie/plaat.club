<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Thread;

#[Strict]
class RemoveReaction implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Remove a reaction this bot previously added to a message in the active thread.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return $thread->supports(Capability::RemoveReaction);
    }

    public function handle(Request $request): string
    {
        $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'emoji' => ['required', 'string', 'max:100'],
        ]);

        $thread = $this->thread();

        if (! $thread->supports(Capability::RemoveReaction)) {
            return $this->unsupported(Capability::RemoveReaction);
        }

        $thread->removeReaction($request->string('message_id')->value(), $request->string('emoji')->value());

        return 'Reaction removed.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->description('The ID of a message in the active thread, taken from a [ListMessages] result. Never invent an ID.')->required(),
            'emoji' => $schema->string()->description('The emoji to remove, as a unicode character or a shortcode name without colons, such as thumbsup.')->required(),
        ];
    }
}
