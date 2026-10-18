<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;

class ActivateEmail extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $code, public readonly string $email, public readonly string $expiresAt) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        // A queued older email must not send after a resend, email change, activation or expiry.
        $user = $notifiable->fresh();

        return $user !== null && ! $user->hasVerifiedEmail() && $user->email === $this->email
            && $user->email_activation_expires_at?->isFuture()
            && $user->email_activation_code_hash !== null && Hash::check($this->code, $user->email_activation_code_hash);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activate your Second Hand account')
            ->greeting('Activate your email address')
            ->line('Enter this six-digit code on Second Hand:')
            ->line('**'.$this->code.'**')
            ->line('The code expires at '.$this->expiresAt.' (UTC). Use the latest code if you request another email.')
            ->line('New accounts must be activated within one hour of registration. Resending does not extend this deadline.')
            ->action('Enter activation code', route('verification.notice'))
            ->line('If you did not create this account, you can ignore this email.');
    }
}
