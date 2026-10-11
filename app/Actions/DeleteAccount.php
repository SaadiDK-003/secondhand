<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeleteProfilePhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAccount
{
    public function __construct(private DeleteListing $deleteListing) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            foreach ($user->listings()->lazyById() as $listing) {
                $this->deleteListing->handle($listing);
            }
            $user->delete();
            if ($user->profile_photo_disk && $user->profile_photo_path) {
                DeleteProfilePhoto::dispatch($user->profile_photo_disk, $user->profile_photo_path)->afterCommit();
            }
        });
    }
}
