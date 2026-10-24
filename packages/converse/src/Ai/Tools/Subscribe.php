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
class Subscribe implements Approvable, Tool
{
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Subscribe the bot to every future message in the active thread, not only mentions.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return ! $thread->isDirectMessage();
    }

    public function handle(Request $request): string
    {
        $this->thread()->subscribe();

        return 'Subscribed to the active thread.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
