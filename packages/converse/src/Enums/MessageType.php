<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

use Laravel\Converse\BotEvents\Message;

enum MessageType: string
{
    case Message = 'message';

    case Mention = 'mention';

    case Command = 'command';

    case Scheduled = 'scheduled';

    public static function fromMessage(Message $message): self
    {
        return match (true) {
            $message->isScheduled() => self::Scheduled,
            $message->isMention() => self::Mention,
            default => self::Message,
        };
    }
}
