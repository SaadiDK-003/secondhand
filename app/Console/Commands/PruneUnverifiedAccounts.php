<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DeleteAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneUnverifiedAccounts extends Command
{
    protected $signature = 'accounts:prune-unverified';

    protected $description = 'Delete new registrations that did not activate their email within one hour';

    public function handle(DeleteAccount $deleteAccount): int
    {
        $count = 0;
        foreach (User::query()->whereNull('email_verified_at')->where('registration_expires_at', '<=', now())->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate, $deleteAccount, &$count): void {
                $user = User::query()->lockForUpdate()->find($candidate->id);
                if (! $user || $user->hasVerifiedEmail() || ! $user->registration_expires_at || $user->registration_expires_at->isFuture()) {
                    return;
                }
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $deleteAccount->handle($user);
                $count++;
            });
        }
        $this->info("Deleted {$count} expired, unverified registrations.");

        return self::SUCCESS;
    }
}
