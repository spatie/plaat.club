<?php

declare(strict_types=1);

namespace Laravel\Converse\Attachments;

use Illuminate\Support\Str;
use Laravel\Converse\Attachment;
use Laravel\Converse\Thread;

class StagingDirectory
{
    public static function forThread(Thread $thread): string
    {
        return implode('/', [
            trim(config()->string('converse.attachments.path'), '/'),
            Str::slug($thread->connection()) ?: 'connection',
            self::hash($thread->encode()),
        ]);
    }

    public static function forAttachment(Thread $thread, Attachment $attachment): string
    {
        return static::forThread($thread).'/'.self::hash($attachment->id() ?? $attachment->url() ?? Str::random(40));
    }

    private static function hash(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }
}
