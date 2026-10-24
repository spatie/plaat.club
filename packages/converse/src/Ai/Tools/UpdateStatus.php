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

#[Strict]
class UpdateStatus implements Tool
{
    use InteractsWithAgentContext;

    public function description(): string
    {
        return 'Show a typing indicator in the active thread. Converse already keeps the indicator alive for the whole run, so use this only to describe the current step.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return $thread->supports(Capability::UpdateStatus);
    }

    public function handle(Request $request): string
    {
        $request->validate(['status' => ['nullable', 'string', 'max:100']]);

        $thread = $this->thread();

        if (! $thread->supports(Capability::UpdateStatus)) {
            return $this->unsupported(Capability::UpdateStatus);
        }

        $status = $request->string('status')->value();

        $thread->updateStatus(blank($status) ? null : $status);

        return 'Typing indicator updated.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('A short status describing the current step, a few words at most. Pass null for a plain typing indicator.')->nullable()->required(),
        ];
    }
}
