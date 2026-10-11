<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use App\Services\SearchListings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Meilisearch\Exceptions\CommunicationException;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_only_current_public_ads_and_safe_card_fields(): void
    {
        $public = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        Listing::factory()->create();
        Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
        Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->addHour()]);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('listings', 1)->where('listings.0.id', $public->id)->missing('listings.0.user_id')->missing('listings.0.mobile_number'));
    }

    public function test_favorites_require_login_are_idempotent_and_belong_to_the_current_user(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $this->put('/favorites/'.$listing->id)->assertRedirect('/login');
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->actingAs($one)->put('/favorites/'.$listing->id, ['user_id' => $two->id])->assertRedirect();
        $this->put('/favorites/'.$listing->id)->assertRedirect();
        $this->assertDatabaseCount('favorites', 1);
        $this->assertDatabaseHas('favorites', ['user_id' => $one->id, 'listing_id' => $listing->id]);
        $this->actingAs($two)->delete('/favorites/'.$listing->id)->assertRedirect();
        $this->assertDatabaseCount('favorites', 1);
        $this->actingAs($one)->get('/favorites')->assertInertia(fn (Assert $page) => $page->has('listings.data', 1)->where('listings.data.0.is_favorite', true));
        $this->delete('/favorites/'.$listing->id)->assertRedirect();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_private_and_expired_ads_cannot_be_saved_and_withdrawn_favorites_are_hidden(): void
    {
        $user = User::factory()->create();
        $private = Listing::factory()->create();
        $this->actingAs($user)->put('/favorites/'.$private->id)->assertNotFound();
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $this->put('/favorites/'.$listing->id)->assertRedirect();
        $listing->forceFill(['status' => ListingStatus::Blocked])->save();
        $this->get('/favorites')->assertInertia(fn (Assert $page) => $page->has('listings.data', 0));
        $this->put('/favorites/'.$listing->id)->assertNotFound();
        $this->delete('/favorites/'.$listing->id)->assertRedirect();
        $this->assertDatabaseCount('favorites', 0);
        $listing->forceFill(['status' => ListingStatus::Published, 'expires_at' => now()->subSecond()])->save();
        $this->put('/favorites/'.$listing->id)->assertNotFound();
    }

    public function test_favorites_cascade_when_listing_is_deleted(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        DB::table('favorites')->insert(['user_id' => $listing->user_id, 'listing_id' => $listing->id]);
        $listing->delete();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_invalid_search_filters_are_rejected_before_calling_search(): void
    {
        $this->mock(SearchListings::class)->shouldNotReceive('search');
        foreach ([['category_id' => 'abc'], ['category_id' => ['1']], ['province_id' => 'abc'], ['city_id' => ['1']], ['sort' => 'price;DROP'], ['page' => 51], ['min_price' => 50, 'max_price' => 10], ['condition' => 'new'], ['q' => str_repeat('x', 121)], ['attributes' => ['fake' => 'x']]] as $filters) {
            $this->getJson('/search?'.http_build_query($filters))->assertUnprocessable();
        }
    }

    public function test_search_outage_is_explicit_and_does_not_leak_exception_details(): void
    {
        $this->mock(SearchListings::class)->shouldReceive('search')->once()->andThrow(new CommunicationException('private connection details'));
        $this->actingAs(User::factory()->create())->get('/search')->assertOk()->assertInertia(fn (Assert $page) => $page->where('unavailable', true)->has('listings.data', 0));
    }

    public function test_index_document_excludes_account_data_and_drafts(): void
    {
        $listing = Listing::factory()->create();
        $this->assertSame([], $listing->toSearchableArray());
        $listing->forceFill(['status' => ListingStatus::Published, 'published_at' => now()])->save();
        $doc = $listing->toSearchableArray();
        $this->assertArrayNotHasKey('user_id', $doc);
        $this->assertArrayNotHasKey('email', $doc);
        $this->assertArrayNotHasKey('mobile_number', $doc);
        $this->assertIsFloat($doc['price']);
    }
}
