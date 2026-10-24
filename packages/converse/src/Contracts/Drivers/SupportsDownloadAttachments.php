<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Attachment;

interface SupportsDownloadAttachments extends Driver
{
    public function downloadAttachment(Attachment $attachment, int $maxBytes, int $timeout): string;
}
