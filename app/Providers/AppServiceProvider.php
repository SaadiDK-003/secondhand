<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\CategoryAttributeOption;
use App\Models\City;
use App\Models\Province;
use App\Models\User;
use App\Services\MarketplaceOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Inertia::disableSsr(fn () => ! config('inertia.ssr.enabled') || ! request()->routeIs('home', 'listings.show', 'browse.city', 'browse.category', 'sellers.show') || auth()->check());
        foreach ([Category::class, CategoryAttribute::class, CategoryAttributeOption::class, Province::class, City::class, Area::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::$event(function (): void {
                    MarketplaceOptions::clear();
                    DB::afterCommit(fn () => MarketplaceOptions::clear());
                });
            }
        }
        Gate::define('moderate', fn (User $user): bool => $user->hasAdminSession());
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, function ($event): void {
            session(['admin_2fa' => $event->user->id.':'.hash('sha256', $event->user->two_factor_secret)]);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
