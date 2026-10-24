<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Http\Controllers\Web\Concerns\RespondsWithoutCaching;
use Laravel\Converse\Http\Requests\Web\WebChatCancelRequest;
use Laravel\Converse\Runs\TurnEvents;
use Laravel\Converse\RunState;

class WebChatCancelController
{
    use RespondsWithoutCaching;

    public function __construct(protected RunStore $runs) {}

    public function __invoke(WebChatCancelRequest $request): JsonResponse
    {
        $run = $this->runs->findRun($request->thread(), $request->runId());

        if (! $run instanceof RunState) {
            return $this->noActiveRun();
        }

        if (! $this->runs->cancel($run->id(), TurnEvents::cancelled($request->threadId(), $run->id()))) {
            return $this->noActiveRun();
        }

        return $this->noStore(['status' => 'accepted', 'threadId' => $request->threadId(), 'runId' => $run->id()], 202);
    }

    protected function noActiveRun(): JsonResponse
    {
        return $this->noStore(['status' => 'no_active_run'], 200);
    }
}
