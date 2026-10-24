<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\Thread;

#[Strict]
class GetChannel implements Tool
{
    use InteractsWithAgentContext;

    public function description(): string
    {
        return 'Get metadata for the active channel: the platform it belongs to, its id, its name, its member count, and whether it is a direct message. Name and member count are unavailable on some platforms.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return ! $thread->isDirectMessage();
    }

    public function handle(Request $request): string
    {
        $thread = $this->thread();
        $channel = $thread->channel();

        return json_encode(array_filter([
            'platform' => $thread->platform(),
            'id' => $channel->id(),
            'is_direct_message' => $thread->isDirectMessage(),
            'name' => rescue(fn (): ?string => $channel->name(), null, false),
            'member_count' => rescue(fn (): ?int => $channel->memberCount(), null, false),
        ], fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
