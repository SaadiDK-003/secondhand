<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Jobs\ExpireListings;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_mark_an_ad_sold_and_it_disappears_immediately(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
        $this->actingAs($listing->user)->post('/listings/'.$listing->id.'/sold', ['status' => 'published'])->assertRedirect('/listings/'.$listing->id.'/preview');
        $this->assertSame(ListingStatus::Sold, $listing->fresh()->status);
        $this->assertNotNull($listing->fresh()->sold_at);
        $this->assertFalse(Listing::published()->whereKey($listing->id)->exists());
        $this->get('/ad/'.$listing->slug)->assertNotFound();
        $this->get('/sitemaps/listings/1.xml')->assertDontSee($listing->slug);
        $this->post('/listings/'.$listing->id.'/sold')->assertForbidden();
        $this->post('/listings/'.$listing->id.'/renew')->assertForbidden();
    }

    public function test_actions_require_authentication_ownership_and_eligible_state(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Expired]);
        foreach (['sold', 'renew'] as $action) {
            $this->post('/listings/'.$listing->id.'/'.$action)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create());
        foreach (['sold', 'renew'] as $action) {
            $this->post('/listings/'.$listing->id.'/'.$action)->assertForbidden();
        }
        $this->actingAs($listing->user);
        foreach ([ListingStatus::Draft, ListingStatus::Pending, ListingStatus::Rejected, ListingStatus::Blocked, ListingStatus::Archived] as $status) {
            $listing->forceFill(['status' => $status])->save();
            $this->post('/listings/'.$listing->id.'/sold')->assertForbidden();
            $this->post('/listings/'.$listing->id.'/renew')->assertForbidden();
        }
    }

    public function test_renewal_keeps_the_ad_but_requires_resubmission_and_review(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Expired, 'published_at' => now()->subDays(31), 'expires_at' => now()->subDay()]);
        $slug = $listing->slug;
        $this->actingAs($listing->user)->post('/listings/'.$listing->id.'/renew')->assertRedirect('/listings/'.$listing->id.'/edit');
        $listing->refresh();
        $this->assertSame(ListingStatus::Draft, $listing->status);
        $this->assertSame($slug, $listing->slug);
        $this->assertNull($listing->published_at);
        $this->assertNull($listing->expires_at);
        $this->get('/ad/'.$slug)->assertNotFound();
        $this->post('/listings/'.$listing->id.'/submit')->assertSessionHasErrors('images');
        $this->assertSame(ListingStatus::Draft, $listing->fresh()->status);
    }

    public function test_renewal_works_at_expiry_even_before_the_worker_runs(): void
    {
        $this->freezeTime();
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subDays(30), 'expires_at' => now()->addMinute()]);
        $this->actingAs($listing->user)->post('/listings/'.$listing->id.'/renew')->assertForbidden();
        $this->travel(1)->minutes();
        $this->post('/listings/'.$listing->id.'/renew')->assertRedirect();
        (new ExpireListings)->handle();
        $this->assertSame(ListingStatus::Draft, $listing->fresh()->status);
    }

    public function test_expiry_is_repeatable_and_preserves_other_states(): void
    {
        $this->freezeTime();
        $due = Listing::factory()->create(['status' => ListingStatus::Published, 'expires_at' => now()]);
        $future = Listing::factory()->create(['status' => ListingStatus::Published, 'expires_at' => now()->addMinute()]);
        $indefinite = Listing::factory()->create(['status' => ListingStatus::Published, 'expires_at' => null]);
        $sold = Listing::factory()->create(['status' => ListingStatus::Sold, 'sold_at' => now(), 'expires_at' => now()->subDay()]);
        (new ExpireListings)->handle();
        (new ExpireListings)->handle();
        $this->assertSame(ListingStatus::Expired, $due->fresh()->status);
        $this->assertSame(ListingStatus::Published, $future->fresh()->status);
        $this->assertSame(ListingStatus::Published, $indefinite->fresh()->status);
        $this->assertSame(ListingStatus::Sold, $sold->fresh()->status);
        $this->assertNotNull($sold->fresh()->sold_at);
    }
}
