<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Attachment;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

interface SupportsSendAttachments extends Driver
{
    public function sendAttachment(Thread $thread, Attachment $attachment, Card|Markdown|string|null $caption = null): SentMessage;
}
