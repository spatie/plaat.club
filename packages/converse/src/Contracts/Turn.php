<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\Runs\RunEventWriter;
use Throwable;

interface Turn
{
    /**
     * @return list<array<string, mixed>>
     */
    public function recordPrompt(): array;

    public function promptMessageId(): ?string;

    public function run(RunEventWriter $events): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function failed(Throwable $exception): array;
}
