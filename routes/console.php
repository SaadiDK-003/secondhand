<?php

use App\Jobs\ExpireListings;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\SiteHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireListings)->everyFiveMinutes()->withoutOverlapping();
Schedule::command('accounts:prune-unverified')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->when(fn (): bool => config('queue.default') === 'redis');
Schedule::call(fn () => Cache::put(SiteHealth::HEARTBEAT_KEY, now()->getTimestamp(), now()->addDay()))
    ->name('site-health-heartbeat')->everyMinute();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:grant {email}', function (): void {
    $user = User::where('email', $this->argument('email'))->firstOrFail();
    if (! $user->two_factor_confirmed_at || $user->suspended_at !== null) {
        $this->error('An active account with confirmed two-factor authentication is required.');

        return;
    }
    DB::transaction(function () use ($user): void {
        $user->forceFill(['is_admin' => true])->save();
        ActivityLog::create(['actor_id' => null, 'action' => 'admin.grant.cli', 'target_type' => 'user', 'target_id' => $user->id, 'details' => ['source' => 'console']]);
    });
    $this->info('Admin access granted. Sign out and sign in with two-factor authentication before visiting /admin.');
})->purpose('Grant admin access to an existing account with confirmed 2FA');
