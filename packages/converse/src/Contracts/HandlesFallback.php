<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

interface HandlesFallback
{
    public function handleFallback(BotEvent $event): void;
}
