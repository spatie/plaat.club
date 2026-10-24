<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';

    case Completed = 'completed';
}
