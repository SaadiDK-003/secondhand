<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ListingImage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListingImageController extends Controller
{
    public function __invoke(ListingImage $image, string $variant): StreamedResponse
    {
        abort_unless(array_key_exists($variant, config('listings.variants')), 404);
        Gate::authorize('view', $image->listing);
        abort_unless($image->status === 'ready', 404);

        return Storage::disk($image->disk)->response($image->directory.'/'.$variant.'.webp', null, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
