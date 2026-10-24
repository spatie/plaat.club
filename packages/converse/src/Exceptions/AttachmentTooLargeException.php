<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

class AttachmentTooLargeException extends AttachmentException
{
    public function __construct(string $message = 'The attachment exceeds the configured maximum size.')
    {
        parent::__construct($message);
    }
}
