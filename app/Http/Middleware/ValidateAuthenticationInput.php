<?php

namespace App\Http\Middleware;

use App\Rules\PasswordByteLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateAuthenticationInput
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Some Fortify controllers normalize credentials or call the broker before full validation.
        $text = ['bail', 'required', 'string', 'not_regex:/\x00/'];
        if ($request->isMethod('POST')) {
            $rules = match (true) {
                $request->routeIs('register.store', 'password.email') => ['email' => [...$text, 'max:255']],
                $request->routeIs('password.update') => [
                    'email' => [...$text, 'email', 'max:255'],
                    'token' => [...$text, 'max:256'],
                    'password' => [...$text, new PasswordByteLimit],
                    'password_confirmation' => [...$text, new PasswordByteLimit],
                ],
                $request->routeIs('password.confirm.store') => ['password' => [...$text, new PasswordByteLimit]],
                $request->routeIs('two-factor.login.store', 'two-factor.confirm') => [
                    'code' => ['bail', 'nullable', 'string', 'regex:/^[0-9]{6}$/'],
                    'recovery_code' => ['bail', 'nullable', 'string', 'not_regex:/\x00/', 'max:128'],
                ],
                default => [],
            };
            if ($rules) {
                $request->validate($rules);
            }
        } elseif ($request->routeIs('password.reset')) {
            $request->validate(['email' => ['bail', 'nullable', 'string', 'email', 'max:255']]);
        }

        return $next($request);
    }
}
