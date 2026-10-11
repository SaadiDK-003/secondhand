<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_profile_is_rendered_before_javascript(): void
    {
        if (! getenv('SSR_TESTS')) {
            $this->markTestSkipped('Set SSR_TESTS=1 with the SSR service running.');
        }
        config(['inertia.ssr.enabled' => true, 'inertia.ssr.throw_on_error' => true]);
        $seller = User::factory()->create(['name' => 'Profile rendering check']);
        Listing::factory()->create(['user_id' => $seller->id, 'status' => ListingStatus::Published, 'published_at' => now()->subDay()]);
        $html = $this->get('/sellers/'.$seller->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Profile rendering check\s*<\/h1>/', $html);
        $this->assertStringContainsString('data-server-rendered="true"', $html);
    }

    public function test_profile_contains_only_safe_account_fields_and_current_items(): void
    {
        $seller = User::factory()->create();
        $visible = Listing::factory()->create(['user_id' => $seller->id, 'status' => ListingStatus::Published, 'published_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
        Listing::factory()->create(['user_id' => $seller->id, 'title' => 'Private draft title']);
        Listing::factory()->create(['user_id' => $seller->id, 'status' => ListingStatus::Published, 'published_at' => now()->subDays(31), 'expires_at' => now()->subDay()]);
        Listing::factory()->create(['user_id' => $seller->id, 'status' => ListingStatus::Published, 'published_at' => now()->addDay()]);
        Listing::factory()->create(['user_id' => $seller->id, 'status' => ListingStatus::Sold, 'sold_at' => now()]);
        $response = $this->get('/sellers/'.$seller->id.'?tracking=example')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('sellers/Show')->where('seller.name', $seller->name)->where('seller.active_count', 1)->where('seller.sold_count', 1)
            ->has('seller.cities', 1)->where('seller.cities.0', $visible->city->name)
            ->missing('seller.email')->missing('seller.mobile_number')->missing('seller.password')->missing('seller.is_admin')
            ->has('listings.data', 1)->where('listings.data.0.id', $visible->id)->where('isOwner', false)
            ->where('seo.canonical', config('app.url').'/sellers/'.$seller->id));
        $response->assertDontSee($seller->email)->assertDontSee($seller->mobile_number)->assertDontSee('Private draft title');
    }

    public function test_suspended_and_non_seller_accounts_are_not_public(): void
    {
        $seller = User::factory()->create();
        Listing::factory()->create(['user_id' => $seller->id]);
        $this->get('/sellers/'.$seller->id)->assertNotFound();
        $this->actingAs($seller)->get('/sellers/'.$seller->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('isOwner', true)->where('seo.robots', 'noindex, follow'));
        $seller->forceFill(['suspended_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $this->get('/sellers/'.$seller->id)->assertNotFound();
    }

    public function test_sold_only_profile_is_noindex_and_does_not_reveal_sold_ads(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Sold, 'sold_at' => now(), 'title' => 'Sold private details']);
        $this->get('/sellers/'.$listing->user_id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('seller.sold_count', 1)->has('listings.data', 0)->where('seo.robots', 'noindex, follow'))->assertDontSee('Sold private details');
    }

    public function test_profiles_paginate_and_favorites_belong_to_the_viewer(): void
    {
        $seller = User::factory()->create();
        $items = Listing::factory()->count(21)->create(['user_id' => $seller->id, 'status' => ListingStatus::Published, 'published_at' => now()->subDay()]);
        $viewer = User::factory()->create();
        $viewer->favorites()->attach($items->last()->id);
        $this->actingAs($viewer)->get('/sellers/'.$seller->id)->assertInertia(fn (Assert $p) => $p->has('listings.data', 20)->where('listings.data.0.is_favorite', true));
        $this->get('/sellers/'.$seller->id.'?page=2')->assertInertia(fn (Assert $p) => $p->has('listings.data', 1)->where('listings.data.0.is_favorite', false)->where('seo.canonical', config('app.url').'/sellers/'.$seller->id.'?page=2'));
        $this->get('/sellers/'.$seller->id.'?page=3')->assertNotFound();
    }
}
