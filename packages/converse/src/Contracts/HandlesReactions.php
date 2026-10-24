<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

use Laravel\Converse\BotEvents\Reaction;

interface HandlesReactions
{
    public function handleReaction(Reaction $reaction): void;
}
