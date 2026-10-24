<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Concerns;

use Illuminate\Support\Collection;
use Laravel\Converse\Approval;
use Laravel\Converse\ApprovalTool;
use Laravel\Converse\Cards\Actions;
use Laravel\Converse\Cards\Button;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\Field;
use Laravel\Converse\Cards\Fields;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

trait RendersApprovalCards
{
    public function requestApproval(Thread $thread, Approval $approval): SentMessage
    {
        return $this->sendMessage($thread, $this->approvalCard($approval));
    }

    /**
     * @param  array<string, bool>  $decisions
     */
    public function resolveApproval(Thread $thread, SentMessage $request, array $decisions): SentMessage
    {
        $card = Card::make(Approval::decisionSummary($decisions));

        $driver = $this;

        if ($driver instanceof SupportsEditMessages) {
            return $driver->editMessage($thread, $request->id(), $card);
        }

        return $this->sendMessage($thread, $card);
    }

    protected function approvalCard(Approval $approval): Card
    {
        $children = $approval->tools()->flatMap(fn (ApprovalTool $tool): array => $this->approvalToolFields($tool))->all();

        $children[] = Actions::make(
            Button::make(Approval::APPROVE_ACTION)->withLabel('Approve')->withValue($approval->token())->primary(),
            Button::make(Approval::REJECT_ACTION)->withLabel('Reject')->withValue($approval->token())->danger(),
        );

        return Card::make($approval->summary())->withChildren(...$children);
    }

    /**
     * @return array<int, Fields>
     */
    protected function approvalToolFields(ApprovalTool $tool): array
    {
        $fields = [Field::make('Tool', $tool->name())];

        if ($tool->reason() !== null && $tool->reason() !== '') {
            $fields[] = Field::make('Reason', $tool->reason());
        }

        foreach ($tool->arguments() as $name => $value) {
            $fields[] = Field::make((string) $name, $this->approvalArgument($value));
        }

        return collect($fields)->chunk(Fields::MAX_FIELDS)->map(fn (Collection $chunk): Fields => Fields::make(...$chunk))->values()->all();
    }

    protected function approvalArgument(mixed $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return $value === '' ? '—' : $value;
    }
}
