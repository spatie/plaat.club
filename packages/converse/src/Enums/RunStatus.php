<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum RunStatus: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }
}
