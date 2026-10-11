<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }
        abort_unless($user->is_admin && $user->suspended_at === null, 403);
        if (! $user->two_factor_confirmed_at || ! $user->two_factor_secret) {
            return redirect('/settings/security')->withErrors(['admin' => 'Enable two-factor authentication before entering administration.']);
        }
        if (! $user->hasAdminSession()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('status', 'Sign in with two-factor authentication to access administration.');
        }

        // Filament uses full HTML pages, while the marketplace uses Inertia.
        if ($request->isMethod('GET') && $request->header('X-Inertia') && ! $request->routeIs('admin.listing-preview')) {
            return Inertia::location($request->fullUrl());
        }

        return $next($request);
    }
}
