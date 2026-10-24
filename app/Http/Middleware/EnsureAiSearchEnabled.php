<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiSearchEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('services.openai.search_enabled'), 404);

        return $next($request);
    }
}
