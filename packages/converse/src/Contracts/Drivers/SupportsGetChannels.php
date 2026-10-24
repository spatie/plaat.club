<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Channel;

interface SupportsGetChannels extends Driver
{
    public function getChannel(string $id): Channel;
}
