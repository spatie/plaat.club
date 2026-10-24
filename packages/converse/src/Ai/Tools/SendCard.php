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

#[Strict]
class SendCard implements Approvable, Tool
{
    use BuildsCards;
    use InteractsWithAgentContext;
    use InteractsWithApprovals;

    public function description(): string
    {
        return "Post a structured card to the active thread: text, images, label / value fields, tables, dividers, buttons, selects and link buttons. Each platform renders it natively. Buttons and selects deliver the user's click back to you as a new turn carrying the action_id and value you defined. The text you return at the end of the turn is still delivered, so use cards for structure, not for your answer.";
    }

    public function handle(Request $request): string
    {
        $request->validate($this->cardRules());

        $card = $this->card($request);

        if (! $card instanceof Card) {
            return $card;
        }

        $message = $this->thread()->send($card);

        return "Card [{$message->id()}] sent.";
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->cardSchema($schema);
    }
}
