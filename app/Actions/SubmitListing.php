<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use App\Services\ModerationChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SubmitListing
{
    public function handle(User $user, Listing $listing): void
    {
        DB::transaction(function () use ($user, $listing): void {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            Gate::forUser($user)->authorize('submit', $locked);
            if ($locked->images()->count() < 1 || $locked->images()->count() > 12 || $locked->images()->where('status', '!=', 'ready')->exists()) {
                throw ValidationException::withMessages(['images' => 'Wait until all photos are ready, or replace any failed photos.']);
            }
            $locked->forceFill(['status' => ListingStatus::Pending, 'moderation_note' => null, 'moderation_flags' => app(ModerationChecks::class)->flags($locked)])->save();
        });
    }
}
