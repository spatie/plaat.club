<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Approval;
use Laravel\Converse\Events\ApprovalRequested;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

class RequestApprovalAction extends Action
{
    public function handle(Thread $thread, Approval $approval): SentMessage
    {
        $driver = $thread->driver();

        $sent = $this->deliver($thread, fn (): SentMessage => $driver->requestApproval($thread, $approval), $approval->summary());

        event(new ApprovalRequested($thread, $approval, $sent));

        return $sent;
    }
}
