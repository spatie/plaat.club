<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use Laravel\Converse\Attachment;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\Drivers\SupportsSendAttachments;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;

class SendAttachmentAction extends Action
{
    public function handle(Thread $thread, Attachment $attachment, Card|Markdown|string|null $caption = null): SentMessage
    {
        $driver = $this->driverFor($thread, SupportsSendAttachments::class);

        return $this->deliver(
            $thread,
            fn (): SentMessage => $driver->sendAttachment($thread, $attachment, $caption),
            $this->textFor($caption ?? $attachment->label()),
            collect([$attachment]),
        );
    }
}
