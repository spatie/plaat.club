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
use Laravel\Converse\Thread;

#[Strict]
class Unsubscribe implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Stop receiving non-mention messages in the active thread. Mentions still reach the bot.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return ! $thread->isDirectMessage();
    }

    public function handle(Request $request): string
    {
        $this->thread()->unsubscribe();

        return 'Unsubscribed from the active thread.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
