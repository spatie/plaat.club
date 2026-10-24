<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

enum ThreadType: string
{
    case Channel = 'channel';

    case DirectMessage = 'direct_message';

    public static function fromDirectMessage(bool $directMessage): self
    {
        return $directMessage ? self::DirectMessage : self::Channel;
    }
}
