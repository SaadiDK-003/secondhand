<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    public function view(?User $user, Listing $listing): bool
    {
        return ($user?->hasAdminSession() ?? false) || $user?->id === $listing->user_id
            || ($listing->user->suspended_at === null && $listing->status === ListingStatus::Published
                && $listing->published_at !== null
                && $listing->published_at->isPast()
                && ($listing->expires_at === null || $listing->expires_at->isFuture()));
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id && in_array($listing->status, [
            ListingStatus::Draft, ListingStatus::Rejected, ListingStatus::Published,
        ], true);
    }

    public function submit(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id && in_array($listing->status, [ListingStatus::Draft, ListingStatus::Rejected], true);
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id && $listing->status !== ListingStatus::Blocked;
    }

    public function markSold(User $user, Listing $listing): bool
    {
        return $user->suspended_at === null && $user->id === $listing->user_id
            && in_array($listing->status, [ListingStatus::Published, ListingStatus::Expired], true);
    }

    public function renew(User $user, Listing $listing): bool
    {
        return $user->suspended_at === null && $user->id === $listing->user_id
            && ($listing->status === ListingStatus::Expired || ($listing->status === ListingStatus::Published
                && $listing->expires_at?->lessThanOrEqualTo(now())));
    }
}
