<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface SupportsCreateInlineResponses extends Driver
{
    public function createInlineResponse(Request $request): ?Response;
}
