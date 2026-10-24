<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Thread;

interface SupportsOpenDirectMessages extends Driver
{
    public function openDirectMessage(string $recipient): Thread;
}
