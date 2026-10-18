<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Notifications\ActivateEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmailActivation
{
    public function send(User $account): void
    {
        DB::transaction(function () use ($account): void {
            $user = User::query()->lockForUpdate()->findOrFail($account->id);
            if ($user->hasVerifiedEmail()) {
                return;
            }
            $this->ensureRegistrationHasNotExpired($user);
            if ($user->email_activation_sent_at?->addMinutes(5)->isFuture()) {
                throw ValidationException::withMessages(['resend' => 'Please wait five minutes between activation emails.']);
            }

            do {
                $code = (string) random_int(100000, 999999);
            } while ($user->email_activation_code_hash && Hash::check($code, $user->email_activation_code_hash));
            $expiresAt = now()->addHour();
            if ($user->registration_expires_at && $user->registration_expires_at->lt($expiresAt)) {
                $expiresAt = $user->registration_expires_at;
            }
            $user->forceFill([
                'email_activation_code_hash' => Hash::make($code),
                'email_activation_sent_at' => now(),
                'email_activation_expires_at' => $expiresAt,
            ])->save();

            // Encrypt queued codes; publish the notification only after the transaction commits.
            $user->notify((new ActivateEmail($code, $user->email, $expiresAt->toIso8601String()))->afterCommit());
        });
        $account->refresh();
    }

    public function verify(User $account, string $code): void
    {
        DB::transaction(function () use ($account, $code): void {
            $user = User::query()->lockForUpdate()->findOrFail($account->id);
            if ($user->hasVerifiedEmail()) {
                return;
            }
            $this->ensureRegistrationHasNotExpired($user);
            if (! $user->email_activation_code_hash || ! $user->email_activation_expires_at?->isFuture()
                || ! Hash::check($code, $user->email_activation_code_hash)) {
                throw ValidationException::withMessages(['code' => 'This activation code is incorrect or has expired. Use the latest code sent to your email.']);
            }

            $user->forceFill([
                'email_verified_at' => now(),
                'registration_expires_at' => null,
                'email_activation_code_hash' => null,
                'email_activation_sent_at' => null,
                'email_activation_expires_at' => null,
            ])->save();
            event(new Verified($user));
        });
        $account->refresh();
    }

    private function ensureRegistrationHasNotExpired(User $user): void
    {
        if ($user->registration_expires_at && ! $user->registration_expires_at->isFuture()) {
            throw ValidationException::withMessages(['code' => 'Your registration has expired. Please register again.']);
        }
    }
}
