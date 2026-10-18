<?php

use App\Http\Controllers\Auth\EmailActivationController;
use App\Http\Controllers\BlockedUserController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ListingImageController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\RequireAdmin;
use App\Models\Listing;
use App\Services\ListingPresenter;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [EmailActivationController::class, 'show'])->name('verification.notice');
    Route::post('/email/verify', [EmailActivationController::class, 'verify'])->middleware('throttle:activation-code')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailActivationController::class, 'send'])->middleware('throttle:6,1,activation-send')->name('verification.send');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/messages', [ConversationController::class, 'index'])->name('messages.index');
    Route::get('/messages/status', [ConversationController::class, 'status'])->middleware('throttle:60,1,message-status')->name('messages.status');
    Route::get('/messages/{conversation}', [ConversationController::class, 'show'])->middleware('throttle:60,1,message-read')->name('messages.show');
    Route::post('/listings/{listing}/messages', [ConversationController::class, 'store'])->middleware('throttle:10,1,message-start')->name('messages.store');
    Route::post('/messages/{conversation}', [ConversationController::class, 'send'])->middleware('throttle:30,1,message-send')->name('messages.send');
    Route::get('/blocked-users', [BlockedUserController::class, 'index'])->name('blocked-users.index');
    Route::put('/blocked-users/{user}', [BlockedUserController::class, 'store'])->middleware('throttle:20,1,user-block')->name('blocked-users.store');
    Route::delete('/blocked-users/{user}', [BlockedUserController::class, 'destroy'])->middleware('throttle:20,1,user-block')->name('blocked-users.destroy');
});

Route::middleware(['auth', 'throttle:5,1,reports'])->group(function (): void {
    Route::post('/listings/{listing}/report', [ReportController::class, 'listing'])->name('reports.listing');
    Route::post('/users/{user}/report', [ReportController::class, 'user'])->name('reports.user');
});
Route::get('/admin/listings/{listing}/preview', function (Listing $listing, ListingPresenter $presenter) {
    $listing->load(['city', 'province', 'area', 'category', 'user', 'images', 'attributeValues.attribute.options']);

    return Inertia::render('listings/Show', ['listing' => $presenter->detail($listing), 'preview' => true, 'canEdit' => false, 'canSubmit' => false]);
})->middleware(['auth', RequireAdmin::class])->name('admin.listing-preview');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemaps/pages.xml', [SitemapController::class, 'pages']);
Route::get('/sitemaps/listings/{page}.xml', [SitemapController::class, 'listings'])->whereNumber('page');
Route::get('/robots.txt', [SitemapController::class, 'robots']);
Route::get('/browse/{city:slug}/{category:slug?}', [BrowseController::class, 'city'])->withoutScopedBindings()->name('browse.city');
Route::get('/category/{category:slug}', [BrowseController::class, 'category'])->name('browse.category');
Route::get('/', [DiscoveryController::class, 'home'])->name('home');
Route::get('/search', [DiscoveryController::class, 'index'])->middleware('throttle:60,1')->name('search');
Route::middleware(['auth', 'throttle:60,1'])->group(function (): void {
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::put('/favorites/{listing}', [FavoriteController::class, 'store'])->name('favorites.store');
    Route::delete('/favorites/{listing}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::get('/ad/{listing:slug}', [ListingController::class, 'show'])->name('listings.show');
Route::get('/sellers/{user}', [SellerController::class, 'show'])->middleware('throttle:60,1')->name('sellers.show');
Route::get('/sellers/{user}/photo', [ProfilePhotoController::class, 'show'])->name('sellers.photo');
Route::get('/listing-images/{image}/{variant}', ListingImageController::class)->name('listing-images.show');
Route::middleware('auth')->group(function (): void {
    Route::get('/my-listings', [ListingController::class, 'index'])->name('listings.index');
    Route::get('/sell', [ListingController::class, 'create'])->name('listings.create');
    Route::post('/listings', [ListingController::class, 'store'])->middleware('throttle:10,1')->name('listings.store');
    Route::get('/listings/{listing}/edit', [ListingController::class, 'edit'])->name('listings.edit');
    Route::patch('/listings/{listing}', [ListingController::class, 'update'])->middleware('throttle:20,1')->name('listings.update');
    Route::get('/listings/{listing}/preview', [ListingController::class, 'preview'])->name('listings.preview');
    Route::post('/listings/{listing}/submit', [ListingController::class, 'submit'])->middleware('throttle:10,1')->name('listings.submit');
    Route::post('/listings/{listing}/sold', [ListingController::class, 'sold'])->middleware('throttle:10,1')->name('listings.sold');
    Route::post('/listings/{listing}/renew', [ListingController::class, 'renew'])->middleware('throttle:10,1')->name('listings.renew');
    Route::delete('/listings/{listing}', [ListingController::class, 'destroy'])->middleware('throttle:20,1')->name('listings.destroy');
});

require __DIR__.'/settings.php';
