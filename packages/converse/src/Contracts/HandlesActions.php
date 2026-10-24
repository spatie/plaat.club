<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Action;

interface HandlesActions
{
    public function handleAction(Action $action): void;
}
