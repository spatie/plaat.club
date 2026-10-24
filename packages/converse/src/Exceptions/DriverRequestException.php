<?php

declare(strict_types=1);

namespace Laravel\Converse\Exceptions;

use Illuminate\Http\Client\Response;

class DriverRequestException extends DriverException
{
    public function __construct(string $message, protected Response $response)
    {
        parent::__construct($message, $response->status());
    }

    public function response(): Response
    {
        return $this->response;
    }
}
