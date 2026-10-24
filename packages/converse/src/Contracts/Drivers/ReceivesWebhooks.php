<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Illuminate\Http\Request;

interface ReceivesWebhooks extends Driver
{
    public function verify(Request $request): bool;
}
