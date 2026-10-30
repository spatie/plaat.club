<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ValidateMigratedSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (URL::hasValidSignature($request)) {
            return $next($request);
        }

        if ($request->getSchemeAndHttpHost() === 'https://plaatclub.com') {
            $legacyRequest = Request::create('https://plaat.club'.$request->getRequestUri());

            if (URL::hasValidSignature($legacyRequest)) {
                return $next($request);
            }
        }

        throw new InvalidSignatureException;
    }
}
