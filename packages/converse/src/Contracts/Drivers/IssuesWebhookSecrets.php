<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

interface IssuesWebhookSecrets extends Driver
{
    public function webhookSecretHint(): string;
}
