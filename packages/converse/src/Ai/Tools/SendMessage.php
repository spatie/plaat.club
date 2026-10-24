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
use Laravel\Converse\Markdown;

#[Strict]
class SendMessage implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Post an additional message to the active thread. The text you return at the end of the turn is already delivered, so do not use this to send your answer. Always scoped to the active thread.';
    }

    public function handle(Request $request): string
    {
        $request->validate(['text' => ['required', 'string', 'max:40000']]);

        $message = $this->thread()->send(Markdown::make($request->string('text')->value()));

        return "Message [{$message->id()}] sent.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The message text. Markdown is rendered for the active platform.')->required(),
        ];
    }
}
