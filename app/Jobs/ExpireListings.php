<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExpireListings implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Listing::where('status', ListingStatus::Published)->where('expires_at', '<=', now())
            ->select('id')->chunkById(100, function ($listings): void {
                foreach ($listings as $listing) {
                    DB::transaction(function () use ($listing): void {
                        $locked = Listing::query()->lockForUpdate()->find($listing->id);
                        if ($locked?->status === ListingStatus::Published && $locked->expires_at?->lessThanOrEqualTo(now())) {
                            // Model events remove the document from Scout after commit.
                            $locked->forceFill(['status' => ListingStatus::Expired])->save();
                        }
                    });
                }
            });
    }
}
