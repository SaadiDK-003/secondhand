<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Services\ListingPresenter;
use App\Services\SeoMetadata;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrowseController extends Controller
{
    public function city(Request $request, City $city, ListingPresenter $presenter, SeoMetadata $seo, ?Category $category = null): Response
    {
        return $this->render($request, $presenter, $seo, $city, $category);
    }

    public function category(Request $request, Category $category, ListingPresenter $presenter, SeoMetadata $seo): Response
    {
        return $this->render($request, $presenter, $seo, null, $category);
    }

    private function render(Request $request, ListingPresenter $presenter, SeoMetadata $seo, ?City $city, ?Category $category): Response
    {
        abort_if($category && ! $category->is_active, 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $query = Listing::published()->with(['category', 'city', 'area', 'images']);
        if ($city) {
            $query->where('city_id', $city->id);
        }
        if ($category) {
            $query->whereIn('category_id', Category::where('id', $category->id)->orWhere('parent_id', $category->id)->select('id'));
        }
        $results = $query->orderByDesc('published_at')->orderByDesc('id')->paginate(20)->withQueryString();
        abort_if($results->currentPage() > $results->lastPage(), 404);
        $favorites = $request->user()?->favorites()->whereIn('listings.id', $results->getCollection()->modelKeys())->pluck('listings.id')->all() ?? [];
        $title = 'Used '.($category->name ?? 'items').($city ? ' in '.$city->name : ' in Pakistan');
        $path = $city ? 'browse/'.$city->slug.($category ? '/'.$category->slug : '') : 'category/'.$category->slug;
        if ($results->currentPage() > 1) {
            $path .= '?page='.$results->currentPage();
        }

        return Inertia::render('discovery/Browse', [
            'heading' => $title, 'seo' => $seo->page($title, 'Browse '.$title.' on Second Hand. Compare pre-owned finds and message sellers directly.', $path, $results->total() > 0),
            'listings' => $results->through(fn (Listing $listing) => $presenter->card($listing, in_array($listing->id, $favorites, true))),
            'searchUrl' => '/search?'.http_build_query(array_filter(['province_id' => $city?->province_id, 'city_id' => $city?->id, 'category_id' => $category?->id])),
        ]);
    }
}
