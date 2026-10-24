<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum RoutingSlot: string
{
    case Mention = 'mention';

    case DirectMessage = 'direct-message';

    case SubscribedMessage = 'subscribed-message';

    case Fallback = 'fallback';
}
