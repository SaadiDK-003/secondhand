<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeleteListingImageFiles;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteListing
{
    public function handle(Listing $listing, ?User $actor = null): void
    {
        DB::transaction(function () use ($listing, $actor): void {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('delete', $locked);
            }
            $images = $locked->images()->get();
            $locked->delete();
            foreach ($images as $image) {
                DeleteListingImageFiles::dispatch($image->id, $image->disk, $image->directory)->afterCommit();
            }
        });
    }
}
