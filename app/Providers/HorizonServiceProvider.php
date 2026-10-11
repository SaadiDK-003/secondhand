<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        Gate::define('viewHorizon', fn (User $user): bool => $user->hasAdminSession());

        // Deliberately omit Horizon's default local-environment bypass.
        Horizon::auth(fn ($request): bool => $request->user() instanceof User
            && Gate::check('viewHorizon', [$request->user()]));
    }
}
