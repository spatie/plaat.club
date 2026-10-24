<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

use Illuminate\Support\Str;

enum AttachmentType: string
{
    case Image = 'image';

    case Video = 'video';

    case Audio = 'audio';

    case Document = 'document';

    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            Str::startsWith($mimeType, 'image/') => self::Image,
            Str::startsWith($mimeType, 'video/') => self::Video,
            Str::startsWith($mimeType, 'audio/') => self::Audio,
            default => self::Document,
        };
    }
}
