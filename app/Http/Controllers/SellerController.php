<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use App\Services\ListingPresenter;
use App\Services\SellerResponseSummary;
use App\Services\SeoMetadata;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerController extends Controller
{
    public function show(Request $request, User $user, ListingPresenter $presenter, SeoMetadata $seo): Response
    {
        abort_if($user->suspended_at !== null, 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $public = Listing::published()->where('user_id', $user->id);
        $sold = $user->listings()->where('status', ListingStatus::Sold)->whereNotNull('sold_at')->count();
        $results = (clone $public)->with(['category', 'city', 'area', 'images'])
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(20);
        abort_if($results->total() === 0 && $sold === 0 && $request->user()?->id !== $user->id, 404);
        abort_if($results->currentPage() > $results->lastPage(), 404);
        $favorites = $request->user()?->favorites()->whereIn('listings.id', $results->getCollection()->modelKeys())->pluck('listings.id')->all() ?? [];
        $path = 'sellers/'.$user->id.($results->currentPage() > 1 ? '?page='.$results->currentPage() : '');

        return Inertia::render('sellers/Show', [
            'seller' => [
                'id' => $user->id, 'name' => $user->name, 'joined' => $user->created_at?->format('M Y'),
                'email_verified' => $user->email_verified_at !== null,
                'photo_url' => $user->profilePhotoUrl(),
                'active_count' => $results->total(), 'sold_count' => $sold,
                'response_summary' => app(SellerResponseSummary::class)->forSeller($user),
                // Locations describe publicly advertised items, never a private home address.
                'cities' => City::whereIn('id', (clone $public)->select('city_id'))->orderBy('name')->pluck('name'),
            ],
            'listings' => $results->through(fn (Listing $listing) => $presenter->card($listing, in_array($listing->id, $favorites, true))),
            'isOwner' => $request->user()?->id === $user->id,
            'seo' => $seo->page($user->name.' — seller profile', 'Browse available second-hand items from '.$user->name.' and contact them through an ad.', $path, $results->total() > 0),
        ]);
    }
}
