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
class DeleteMessage implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Delete a message this bot posted in the active thread. Deletion cannot be undone, and messages from other users cannot be deleted.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return $thread->supports(Capability::DeleteMessage);
    }

    public function handle(Request $request): string
    {
        $request->validate(['message_id' => ['required', 'string', 'max:255']]);

        $thread = $this->thread();

        if (! $thread->supports(Capability::DeleteMessage)) {
            return $this->unsupported(Capability::DeleteMessage);
        }

        $messageId = $request->string('message_id')->value();

        if (! $thread->hasSent($messageId)) {
            return $this->notSent('deleted');
        }

        $thread->delete($messageId);

        return 'Message deleted.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->description('The ID of a message this bot posted in the active thread, taken from a [SendMessage] or [ListMessages] result. Never invent an ID.')->required(),
        ];
    }
}
