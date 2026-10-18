<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\PakistanMobile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginRequest::class, \App\Http\Requests\Auth\LoginRequest::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $identifier = trim($request->string('email')->toString());
            $user = str_contains($identifier, '@')
                ? User::where('email', Str::lower($identifier))->first()
                : User::where('mobile_number', PakistanMobile::normalize($identifier))->first();

            if ($user && $user->suspended_at === null && (! $user->isLocalDemoAccount() || app()->environment('local')) && Hash::check($request->string('password')->toString(), $user->password)) {
                if (Hash::needsRehash($user->password)) {
                    $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();
                }

                return $user;
            }

            return null;
        });

        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            ...($this->app->environment('local') ? ['demoCredentials' => $this->demoCredentials()] : []),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /** @return array{password: string, accounts: list<array{label: string, email: string}>}|null */
    private function demoCredentials(): ?array
    {
        $accounts = [];
        $password = config('demo.password');

        foreach (config('demo.accounts') as $account) {
            $user = User::where('email', $account['email'])->first();
            if ($user && ! $user->is_admin && $user->suspended_at === null && $user->two_factor_secret === null && Hash::check($password, $user->password)) {
                $accounts[] = ['label' => $account['label'], 'email' => $account['email']];
            }
        }

        return $accounts ? ['password' => $password, 'accounts' => $accounts] : null;
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('activation-code', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));

        RateLimiter::for('registration', function (Request $request) {
            return $request->isMethod('post') && $request->routeIs('register.store')
                ? Limit::perMinute(5)->by($request->ip())
                : Limit::none();
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            // This middleware runs before the FormRequest rejects malformed input.
            $input = $request->input(Fortify::username());
            $identifier = is_string($input) ? trim(mb_substr($input, 0, 255)) : '';
            $identifier = str_contains($identifier, '@') ? Str::lower($identifier) : PakistanMobile::normalize($identifier);
            $throttleKey = hash('sha256', $identifier).'|'.$request->ip();

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}
