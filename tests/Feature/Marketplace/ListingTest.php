<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Area;
use App\Models\Listing;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_defaults_casts_relationships_and_stable_slug(): void
    {
        $listing = Listing::factory()->create(['price' => '19999.95']);
        $second = Listing::factory()->create(['title' => $listing->title]);
        $this->assertSame(ListingStatus::Draft, $listing->fresh()->status);
        $this->assertSame(ListingCondition::Good, $listing->fresh()->condition);
        $this->assertSame('19999.95', $listing->fresh()->price);
        $this->assertSame('PKR', $listing->fresh()->currency);
        $this->assertTrue($listing->user->listings()->whereKey($listing->id)->exists());
        $this->assertSame($listing->province_id, $listing->city->province_id);
        $this->assertNotSame($listing->slug, $second->slug);
        $slug = $listing->slug;
        $listing->update(['title' => 'Changed title']);
        $this->assertSame($slug, $listing->fresh()->slug);
    }

    public function test_owner_status_and_publication_dates_cannot_be_mass_assigned(): void
    {
        $listing = Listing::factory()->create();
        foreach (['user_id', 'status', 'currency', 'slug', 'published_at', 'sold_at'] as $field) {
            $this->assertFalse($listing->isFillable($field));
        }
    }

    public function test_public_scope_excludes_unpublished_sold_expired_and_future_listings(): void
    {
        $this->travelTo(now()->startOfSecond());
        $visible = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subMinute()]);
        Listing::factory()->create();
        Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => null]);
        Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->addDay()]);
        Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subDay(), 'expires_at' => now()]);
        Listing::factory()->create(['status' => ListingStatus::Sold, 'published_at' => now()->subDay()]);
        Listing::factory()->create(['status' => ListingStatus::Blocked, 'published_at' => now()->subDay()]);
        $this->assertSame([$visible->id], Listing::published()->pluck('id')->all());
    }

    public function test_listing_policy_limits_changes_to_the_owner_and_eligible_statuses(): void
    {
        $listing = Listing::factory()->create();
        $owner = Gate::forUser($listing->user);
        $other = Gate::forUser(User::factory()->create());
        $this->assertTrue($owner->allows('view', $listing));
        $this->assertTrue($owner->allows('update', $listing));
        $this->assertTrue($owner->allows('delete', $listing));
        $this->assertFalse($other->allows('view', $listing));
        $this->assertFalse($other->allows('update', $listing));
        $this->assertFalse($other->allows('delete', $listing));
        $this->assertFalse(Gate::allows('view', $listing));
        $listing->forceFill(['status' => ListingStatus::Published, 'published_at' => now()->subMinute()])->save();
        $this->assertTrue(Gate::allows('view', $listing));
        $listing->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertFalse(Gate::allows('view', $listing));
        $listing->forceFill(['status' => ListingStatus::Blocked])->save();
        $this->assertFalse($owner->allows('update', $listing));
        $this->assertFalse($owner->allows('delete', $listing));
    }

    public function test_database_rejects_city_from_another_province(): void
    {
        $listing = Listing::factory()->create();
        $province = Province::factory()->create();
        $this->expectException(QueryException::class);
        $listing->update(['province_id' => $province->id]);
    }

    public function test_database_rejects_area_from_another_city(): void
    {
        $listing = Listing::factory()->create();
        $area = Area::factory()->create();
        $this->expectException(QueryException::class);
        $listing->update(['area_id' => $area->id]);
    }

    public function test_database_rejects_negative_price(): void
    {
        $this->expectException(QueryException::class);
        Listing::factory()->create(['price' => '-0.01']);
    }

    public function test_database_rejects_unknown_status(): void
    {
        $listing = Listing::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('listings')->where('id', $listing->id)->update(['status' => 'unknown']);
    }

    public function test_database_rejects_invalid_coordinates(): void
    {
        $this->expectException(QueryException::class);
        Listing::factory()->create(['latitude' => 100, 'longitude' => 60]);
    }
}
