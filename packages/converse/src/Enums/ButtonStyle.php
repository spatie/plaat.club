<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum ButtonStyle: string
{
    case Default = 'default';
    case Primary = 'primary';
    case Danger = 'danger';
}
