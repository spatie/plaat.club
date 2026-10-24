<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum ThreadStatus: string
{
    case Thinking = 'is thinking...';

    case Idle = '';
}
