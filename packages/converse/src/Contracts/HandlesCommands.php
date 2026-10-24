<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Command;

interface HandlesCommands
{
    public function handleCommand(Command $command): void;
}
