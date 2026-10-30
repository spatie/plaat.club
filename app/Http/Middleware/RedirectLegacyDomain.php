<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getHost(), ['plaat.club', 'www.plaat.club', 'www.plaatclub.com'], true)) {
            return redirect()->away('https://plaatclub.com'.$request->getRequestUri(), 308);
        }

        return $next($request);
    }
}
