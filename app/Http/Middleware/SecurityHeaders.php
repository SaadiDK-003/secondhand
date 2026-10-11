<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($next($request), $request);
    }

    public function apply(Response $response, Request $request): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // This restricts embedding and document URLs; a strict script policy needs separate Livewire/SSR validation.
        $response->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");

        if ($request->user() || $request->is('login', 'register', 'forgot-password', 'reset-password/*', 'two-factor-challenge', 'user/*', 'settings/*', 'admin', 'admin/*', 'horizon', 'horizon/*', 'livewire/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
