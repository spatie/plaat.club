<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Approval;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Events\ApprovalResolved;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

class ResolveApprovalAction extends Action
{
    public function __construct(protected ConversationStore $conversations) {}

    /**
     * @param  array<string, bool>  $decisions
     */
    public function handle(Thread $thread, Approval $approval, SentMessage $request, array $decisions): SentMessage
    {
        $driver = $thread->driver();
        $text = Approval::decisionSummary($decisions);

        $sent = $this->send($thread, fn (): SentMessage => $driver->resolveApproval($thread, $request, $decisions));

        $sent->id() === $request->id()
            ? $this->conversations->updateBotMessage($thread, $request->id(), $text)
            : $this->conversations->storeBotMessage($sent, $text);

        event(new ApprovalResolved($thread, $approval, $request, $decisions));

        return $sent;
    }
}
