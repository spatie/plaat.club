<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum TerminalDisplay: string
{
    case Full = 'full';

    case Collapsed = 'collapsed';

    case Hidden = 'hidden';
}
