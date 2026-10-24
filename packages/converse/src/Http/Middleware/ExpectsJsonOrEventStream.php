<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpectsJsonOrEventStream
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json, text/event-stream');
        }

        return $next($request);
    }
}
