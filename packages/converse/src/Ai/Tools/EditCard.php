<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Converse\Ai\Tools\Concerns\BuildsCards;
use Laravel\Converse\Ai\Tools\Concerns\InteractsWithAgentContext;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Enums\Capability;
use Laravel\Converse\Thread;

#[Strict]
class EditCard implements Approvable, Tool
{
    use BuildsCards;
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Replace a message this bot posted in the active thread with a structured card. Use it to turn a progress message or an earlier card into its final form, or to disable buttons the user already clicked. Messages from other users cannot be edited.';
    }

    public function shouldRegister(Thread $thread): bool
    {
        return $thread->supports(Capability::EditMessage);
    }

    public function handle(Request $request): string
    {
        $request->validate(['message_id' => ['required', 'string', 'max:255'], ...$this->cardRules()]);

        $card = $this->card($request);

        if (! $card instanceof Card) {
            return $card;
        }

        $thread = $this->thread();

        if (! $thread->supports(Capability::EditMessage)) {
            return $this->unsupported(Capability::EditMessage);
        }

        $messageId = $request->string('message_id')->value();

        if (! $thread->hasSent($messageId)) {
            return $this->notSent('edited');
        }

        $message = $thread->edit($messageId, $card);

        return "Card [{$message->id()}] edited.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->description('The ID of a message this bot posted in the active thread, taken from a [SendCard], [SendMessage] or [ListMessages] result. Never invent an ID.')->required(),
            ...$this->cardSchema($schema),
        ];
    }
}
