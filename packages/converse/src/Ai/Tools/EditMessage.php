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
use Laravel\Converse\Markdown;
use Laravel\Converse\Thread;

#[Strict]
class EditMessage implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Edit a message this bot posted in the active thread. Messages from other users cannot be edited.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return $thread->supports(Capability::EditMessage);
    }

    public function handle(Request $request): string
    {
        $request->validate([
            'message_id' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:40000'],
        ]);

        $thread = $this->thread();

        if (! $thread->supports(Capability::EditMessage)) {
            return $this->unsupported(Capability::EditMessage);
        }

        $messageId = $request->string('message_id')->value();

        if (! $thread->hasSent($messageId)) {
            return $this->notSent('edited');
        }

        $message = $thread->edit($messageId, Markdown::make($request->string('text')->value()));

        return "Message [{$message->id()}] edited.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->description('The ID of a message this bot posted in the active thread, taken from a [SendMessage] or [ListMessages] result. Never invent an ID.')->required(),
            'text' => $schema->string()->description('The full replacement text. It replaces the message rather than appending to it.')->required(),
        ];
    }
}
