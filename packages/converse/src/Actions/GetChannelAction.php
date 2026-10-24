<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Channel;
use Laravel\Converse\Contracts\Drivers\SupportsGetChannels;
use Laravel\Converse\Thread;

class GetChannelAction extends Action
{
    public function handle(Thread $thread): Channel
    {
        return $this->driverFor($thread, SupportsGetChannels::class)
            ->getChannel($thread->channel()->id());
    }
}
