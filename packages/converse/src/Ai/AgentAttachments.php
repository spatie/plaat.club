<?php

declare(strict_types=1);

namespace Laravel\Converse\Ai;

use Illuminate\Support\Collection;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Converse\Attachment;
use Laravel\Converse\Attachments\UploadPolicy;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Enums\AttachmentType;

class AgentAttachments
{
    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, File>
     */
    public static function for(Collection $messages): Collection
    {
        return $messages
            ->flatMap(fn (Message $message): Collection => $message->attachments())
            ->filter(fn (Attachment $attachment): bool => $attachment->isStored())
            ->reverse()
            ->take(UploadPolicy::fromConfig()->maxFiles())
            ->reverse()
            ->map(static::file(...))
            ->values();
    }

    protected static function file(Attachment $attachment): File
    {
        return (match ($attachment->type()) {
            AttachmentType::Image => Image::fromStorage((string) $attachment->path(), $attachment->disk()),
            AttachmentType::Video => Video::fromStorage((string) $attachment->path(), $attachment->disk()),
            AttachmentType::Audio => Audio::fromStorage((string) $attachment->path(), $attachment->disk()),
            AttachmentType::Document => Document::fromStorage((string) $attachment->path(), $attachment->disk()),
        })->as($attachment->label())->withMimeType($attachment->mediaType());
    }
}
