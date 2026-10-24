<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Thread;

interface SupportsUpdateStatuses extends Driver
{
    public function updateStatus(Thread $thread, ThreadStatus|string|null $status = null): void;
}
