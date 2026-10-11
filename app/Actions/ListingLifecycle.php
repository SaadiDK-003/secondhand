<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListingLifecycle
{
    public function sold(User $user, Listing $listing): void
    {
        DB::transaction(function () use ($user, $listing): void {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            Gate::forUser($user)->authorize('markSold', $locked);
            $locked->forceFill(['status' => ListingStatus::Sold, 'sold_at' => now()])->save();
        });
    }

    public function renew(User $user, Listing $listing): void
    {
        DB::transaction(function () use ($user, $listing): void {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            Gate::forUser($user)->authorize('renew', $locked);
            // Keep the same ad and photos; the seller reviews it before resubmission.
            $locked->forceFill(['status' => ListingStatus::Draft, 'published_at' => null,
                'expires_at' => null, 'sold_at' => null, 'moderation_note' => null,
                'moderation_flags' => null])->save();
        });
    }
}
