<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Bot = 'bot';
}
