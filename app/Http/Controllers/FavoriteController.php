<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Services\ListingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FavoriteController extends Controller
{
    public function index(Request $request, ListingPresenter $presenter): Response
    {
        $request->validate(['page' => ['bail', 'nullable', 'integer', 'min:1', 'max:1000']]);
        $listings = $request->user()->favorites()->published()->with(['category', 'city', 'area', 'images'])->orderByPivot('created_at', 'desc')->paginate(20);

        return Inertia::render('discovery/Favorites', ['listings' => $listings->through(fn (Listing $listing) => $presenter->card($listing, true))]);
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        DB::transaction(function () use ($request, $listing): void {
            abort_unless(Listing::published()->whereKey($listing->id)->lockForUpdate()->first() !== null, 404);
            DB::table('favorites')->insertOrIgnore(['user_id' => $request->user()->id, 'listing_id' => $listing->id, 'created_at' => now()]);
        });

        return back();
    }

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $request->user()->favorites()->detach($listing->id);

        return back();
    }
}
