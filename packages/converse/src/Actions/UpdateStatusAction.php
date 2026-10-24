<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Contracts\Drivers\SupportsUpdateStatuses;
use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Thread;

class UpdateStatusAction extends Action
{
    public function handle(Thread $thread, ThreadStatus|string|null $status = null): void
    {
        if (is_string($status) && blank($status)) {
            $status = ThreadStatus::Idle;
        }

        rescue(function () use ($thread, $status): void {
            $this->optionalDriverFor($thread, SupportsUpdateStatuses::class)
                ?->updateStatus($thread, $status);
        });
    }
}
