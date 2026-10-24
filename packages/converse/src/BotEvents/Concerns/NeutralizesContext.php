<?php

declare(strict_types=1);

namespace Laravel\Converse\BotEvents\Concerns;

trait NeutralizesContext
{
    protected function neutralize(string $value): string
    {
        return (string) preg_replace('~<(?=\s*/?\s*(?:message|content|attachments?|action))~i', '<\\\\', $value);
    }
}
