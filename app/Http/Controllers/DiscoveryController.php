<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListingCondition;
use App\Http\Requests\SearchListingsRequest;
use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Services\ListingPresenter;
use App\Services\MarketplaceOptions;
use App\Services\SearchListings;
use App\Services\SeoMetadata;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;

class DiscoveryController extends Controller
{
    public function index(SearchListingsRequest $request, SearchListings $search, ListingPresenter $presenter): Response
    {
        $filters = $request->validated();
        $unavailable = false;
        try {
            $results = $search->search($filters);
        } catch (ApiException|CommunicationException $exception) {
            report($exception);
            $unavailable = true;
            $results = new LengthAwarePaginator([], 0, 20);
        }
        $favorites = $request->user()?->favorites()->whereIn('listings.id', $results->getCollection()->pluck('id')->all())->pluck('listings.id')->all() ?? [];

        return Inertia::render('discovery/Index', [
            'filters' => $filters, 'unavailable' => $unavailable,
            'listings' => $results->through(fn (Listing $listing) => $presenter->card($listing, in_array($listing->id, $favorites, true))),
            ...$this->options(),
        ]);
    }

    public function home(Request $request, ListingPresenter $presenter): Response
    {
        $listings = Listing::published()->with(['category', 'city', 'area', 'images'])->latest('published_at')->limit(8)->get();
        $favorites = $request->user()?->favorites()->whereIn('listings.id', $listings->modelKeys())->pluck('listings.id')->all() ?? [];

        return Inertia::render('Welcome', [
            'seo' => app(SeoMetadata::class)->home(),
            'cities' => City::orderBy('name')->get(['name', 'slug']),
            'categories' => Category::whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
            'listings' => $listings->map(fn (Listing $listing) => $presenter->card($listing, in_array($listing->id, $favorites, true))),
        ]);
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return [
            ...app(MarketplaceOptions::class)->discovery(),
            'conditions' => array_map(fn (ListingCondition $condition) => ['value' => $condition->value, 'label' => ucwords(str_replace('_', ' ', $condition->value))], ListingCondition::cases()),
        ];
    }
}
