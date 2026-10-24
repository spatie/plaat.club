<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Controllers\Web\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithoutCaching
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function noStore(array $payload, int $status): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Cache-Control' => 'no-store']);
    }
}
