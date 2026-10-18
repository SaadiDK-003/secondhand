<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\EmailActivation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateEmailRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailActivationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/VerifyEmail', [
            'email' => $user->email,
            'expiresAt' => $user->email_activation_expires_at?->toIso8601String(),
            'registrationExpiresAt' => $user->registration_expires_at?->toIso8601String(),
            'resendAvailableAt' => $user->email_activation_sent_at?->addMinutes(5)->toIso8601String(),
            'serverNow' => now()->toIso8601String(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function verify(ActivateEmailRequest $request, EmailActivation $activation): RedirectResponse
    {
        $activation->verify($request->user(), $request->string('code')->toString());
        $request->session()->regenerate();

        return to_route('dashboard')->with('status', 'Your email address is activated.');
    }

    public function send(Request $request, EmailActivation $activation): RedirectResponse
    {
        $activation->send($request->user());

        return to_route('verification.notice')->with('status', 'A new activation code has been queued for your email. Check your inbox and spam folder.');
    }
}
