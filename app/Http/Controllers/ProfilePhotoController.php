<?php

namespace App\Http\Controllers;

use App\Actions\UpdateProfilePhoto;
use App\Enums\ListingStatus;
use App\Http\Requests\ProfilePhotoRequest;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfilePhotoController extends Controller
{
    public function store(ProfilePhotoRequest $request, UpdateProfilePhoto $action): RedirectResponse
    {
        $action->handle($request->user(), $request->file('photo'));

        return to_route('profile.edit');
    }

    public function destroy(Request $request, UpdateProfilePhoto $action): RedirectResponse
    {
        $action->handle($request->user(), null);

        return to_route('profile.edit');
    }

    public function show(Request $request, User $user): StreamedResponse
    {
        abort_if($user->suspended_at !== null || ! $user->profile_photo_path, 404);
        abort_unless($request->user()?->id === $user->id
            || Listing::published()->where('user_id', $user->id)->exists()
            || $user->listings()->where('status', ListingStatus::Sold)->whereNotNull('sold_at')->exists(), 404);

        return Storage::disk($user->profile_photo_disk)->response($user->profile_photo_path, null, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
