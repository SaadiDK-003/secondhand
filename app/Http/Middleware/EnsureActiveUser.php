<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ($user->suspended_at !== null || ($user->isLocalDemoAccount() && ! app()->environment('local')))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'This account is unavailable.');
        }

        if ($user && ! $user->hasVerifiedEmail()) {
            if ($user->registration_expires_at && ! $user->registration_expires_at->isFuture()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('register')->with('status', 'Your registration expired. Please register again.');
            }
            if (! $request->routeIs('verification.*', 'logout')) {
                if ($request->expectsJson()) {
                    abort(403, 'Activate your email address before using your account.');
                }

                return redirect()->route('verification.notice');
            }
        }

        return $next($request);
    }
}
