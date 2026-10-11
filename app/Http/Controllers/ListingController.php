<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteListing;
use App\Actions\ListingLifecycle;
use App\Actions\SaveListing;
use App\Actions\SubmitListing;
use App\Enums\ListingCondition;
use App\Http\Requests\SaveListingRequest;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Province;
use App\Services\ListingPresenter;
use App\Services\SeoMetadata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function index(Request $request, ListingPresenter $presenter): Response
    {
        $request->validate(['page' => ['bail', 'nullable', 'integer', 'min:1', 'max:1000']]);
        $listings = $request->user()->listings()->with(['city', 'category', 'images'])->latest()->paginate(12);

        return Inertia::render('listings/Index', ['listings' => $listings->through(fn (Listing $listing) => $presenter->summary($listing))]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Listing::class);

        return Inertia::render('listings/Form', [...$this->formOptions(), 'listing' => null]);
    }

    public function store(SaveListingRequest $request, SaveListing $save): RedirectResponse
    {
        $listing = $save->handle($request->user(), $request->validated(), $request->photos());

        return to_route('listings.preview', $listing);
    }

    public function edit(Listing $listing, ListingPresenter $presenter): Response
    {
        Gate::authorize('update', $listing);
        $this->loadDetail($listing);

        return Inertia::render('listings/Form', [...$this->formOptions(), 'listing' => $presenter->detail($listing)]);
    }

    public function update(SaveListingRequest $request, Listing $listing, SaveListing $save): RedirectResponse
    {
        $save->handle($request->user(), $request->validated(), $request->photos(), $request->validated('remove_image_ids', []), $listing);

        return to_route('listings.preview', $listing);
    }

    public function preview(Request $request, Listing $listing, ListingPresenter $presenter): Response
    {
        abort_unless($request->user()->id === $listing->user_id, 403);
        $this->loadDetail($listing);

        return Inertia::render('listings/Show', [
            'listing' => $presenter->detail($listing), 'preview' => true, 'moderationNote' => $listing->moderation_note,
            'canEdit' => $request->user()->can('update', $listing),
            'canSubmit' => $request->user()->can('submit', $listing),
            'canMarkSold' => $request->user()->can('markSold', $listing),
            'canRenew' => $request->user()->can('renew', $listing),
            'isPublic' => Listing::published()->whereKey($listing->id)->exists(),
        ]);
    }

    public function show(Request $request, Listing $listing, ListingPresenter $presenter): Response
    {
        abort_unless(Listing::published()->whereKey($listing->id)->exists(), 404);
        $this->loadDetail($listing);

        return Inertia::render('listings/Show', ['listing' => $presenter->detail($listing), 'preview' => false, 'seo' => app(SeoMetadata::class)->listing($listing), 'canEdit' => false, 'canSubmit' => false, 'canMessage' => $request->user()?->id !== $listing->user_id, 'isFavorite' => $request->user()?->favorites()->whereKey($listing->id)->exists() ?? false]);
    }

    public function submit(Request $request, Listing $listing, SubmitListing $submit): RedirectResponse
    {
        $submit->handle($request->user(), $listing);

        return to_route('listings.preview', $listing);
    }

    public function destroy(Request $request, Listing $listing, DeleteListing $delete): RedirectResponse
    {
        Gate::authorize('delete', $listing);
        $delete->handle($listing, $request->user());

        return to_route('listings.index');
    }

    public function sold(Request $request, Listing $listing, ListingLifecycle $lifecycle): RedirectResponse
    {
        $lifecycle->sold($request->user(), $listing);

        return to_route('listings.preview', $listing);
    }

    public function renew(Request $request, Listing $listing, ListingLifecycle $lifecycle): RedirectResponse
    {
        $lifecycle->renew($request->user(), $listing);

        return to_route('listings.edit', $listing);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'categories' => Category::where('is_active', true)->with(['attributes.options'])->orderBy('sort_order')->get(),
            'provinces' => Province::with(['cities.areas'])->orderBy('name')->get(),
            'conditions' => array_map(fn (ListingCondition $condition) => ['value' => $condition->value, 'label' => ucwords(str_replace('_', ' ', $condition->value))], ListingCondition::cases()),
        ];
    }

    private function loadDetail(Listing $listing): void
    {
        $listing->load(['city', 'province', 'area', 'category', 'user', 'images', 'attributeValues.attribute.options']);
    }
}
