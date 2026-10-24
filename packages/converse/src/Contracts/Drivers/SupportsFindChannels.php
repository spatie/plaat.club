<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Channel;

interface SupportsFindChannels extends Driver
{
    public function findChannel(string $name): ?Channel;
}
