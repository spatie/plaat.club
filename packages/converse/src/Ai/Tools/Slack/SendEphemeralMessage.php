<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools\Slack;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

#[Strict]
class SendEphemeralMessage implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Post a Slack ephemeral message that only the sender of the current request can see. Nobody else in the thread can read it, and it disappears when they reload. Only available on Slack connections.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return $thread->supports(Capability::SendEphemeralMessage) && $thread->user() instanceof User && ! $thread->user()->isAnonymous();
    }

    public function handle(Request $request): string
    {
        $request->validate(['text' => ['required', 'string', 'max:40000']]);

        $thread = $this->thread();

        if (! $thread->supports(Capability::SendEphemeralMessage)) {
            return $this->unsupported(Capability::SendEphemeralMessage);
        }

        if (! $thread->user() instanceof User) {
            return 'The active thread has no known sender to receive an ephemeral message.';
        }

        $message = $thread->sendEphemeral($request->string('text')->value());

        return "Ephemeral message [{$message->id()}] sent.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The message text. Markdown is rendered for the active platform.')->required(),
        ];
    }
}
