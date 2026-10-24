<?php

declare(strict_types=1);

namespace Laravel\Converse\Storage\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Converse\ConverseManager;
use Laravel\Converse\Models\Thread as ThreadModel;
use Laravel\Converse\Thread;

trait InteractsWithThreadRows
{
    /**
     * @return Builder<ThreadModel>
     */
    protected function threadQuery(Thread $thread): Builder
    {
        return ConverseManager::threadModel()::query()->where($this->threadAttributes($thread));
    }

    /**
     * @return array{bot: string, connection: string, external_id: string}
     */
    protected function threadAttributes(Thread $thread): array
    {
        return [
            'bot' => $thread->bot() ?? '',
            'connection' => $thread->connection(),
            'external_id' => $thread->externalId(),
        ];
    }
}
