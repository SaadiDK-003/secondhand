<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $mobile_number
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'mobile_number', 'password'])]
#[Hidden(['profile_photo_disk', 'profile_photo_path', 'mobile_number', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'suspension_reason'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    public function hasAdminSession(): bool
    {
        return $this->is_admin && $this->suspended_at === null && $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null
            && session('admin_2fa') === $this->id.':'.hash('sha256', $this->two_factor_secret);
    }

    public function isLocalDemoAccount(): bool
    {
        return in_array($this->email, array_column(config('demo.accounts', []), 'email'), true);
    }

    public function profilePhotoUrl(): ?string
    {
        return $this->profile_photo_path ? '/sellers/'.$this->id.'/photo?v='.substr(hash('sha256', $this->profile_photo_path), 0, 12) : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAdminSession();
    }

    /** @return BelongsToMany<Listing, $this> */
    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'favorites')->withPivot('created_at');
    }

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'suspended_at' => 'immutable_datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
