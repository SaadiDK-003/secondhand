<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Actions\SyncListingAttributes;
use App\Enums\AttributeType;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\Listing;
use App\Services\SearchListings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Meilisearch\Client;
use Tests\TestCase;

class MeilisearchTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $indexName;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('MEILISEARCH_TESTS')) {
            $this->markTestSkipped('Set MEILISEARCH_TESTS=1 with a local Meilisearch server.');
        }
        config(['scout.driver' => 'meilisearch', 'scout.prefix' => 'test_'.strtolower((string) Str::ulid()).'_']);
        $this->client = new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key'));
        $this->indexName = (new Listing)->indexableAs();
        $task = $this->client->index($this->indexName)->updateSettings(config('scout.meilisearch.index-settings')[Listing::class]);
        $result = $this->client->waitForTask($task['taskUid'], 10000);
        $this->assertSame('succeeded', $result['status']);
    }

    protected function tearDown(): void
    {
        if (isset($this->client,$this->indexName)) {
            $this->client->deleteIndex($this->indexName);
        }
        parent::tearDown();
    }

    private function publish(array $fields = []): Listing
    {
        return Listing::factory()->create([...['status' => ListingStatus::Published, 'published_at' => now()->subHour()], ...$fields]);
    }

    private function index(Listing ...$listings): void
    {
        $task = $this->client->index($this->indexName)->addDocuments(array_map(fn ($l) => $l->fresh()->toSearchableArray(), $listings), 'id');
        $result = $this->client->waitForTask($task['taskUid'], 10000);
        $this->assertSame('succeeded', $result['status']);
    }

    public function test_keyword_typo_tolerance_price_sort_and_pagination(): void
    {
        $cheap = $this->publish(['title' => 'Comfortable emerald sofa', 'price' => 10000]);
        $costly = $this->publish(['title' => 'Comfortable velvet sofa', 'price' => 30000]);
        $this->index($cheap, $costly);
        $results = app(SearchListings::class)->search(['q' => 'comfortble', 'sort' => 'price_asc']);
        $this->assertSame([$cheap->id, $costly->id], $results->getCollection()->pluck('id')->all());
        $this->assertSame([$costly->id], app(SearchListings::class)->search(['min_price' => 20000])->getCollection()->pluck('id')->all());
        $this->assertSame([$costly->id, $cheap->id], app(SearchListings::class)->search(['q' => 'sofa', 'sort' => 'price_desc'])->getCollection()->pluck('id')->all());
        $this->get('/search?q=comfortble')->assertOk()->assertInertia(fn (Assert $page) => $page->where('unavailable', false)->has('listings.data', 2));
        $others = Listing::factory()->count(21)->create(['status' => ListingStatus::Published, 'published_at' => now()->subMinute(), 'title' => 'Another sofa']);
        $this->index(...$others->all());
        $page = app(SearchListings::class)->search(['q' => 'sofa', 'page' => 2]);
        $this->assertSame(23, $page->total());
        $this->assertCount(3, $page->items());
    }

    public function test_parent_category_location_condition_and_dynamic_filters(): void
    {
        $parent = Category::factory()->create();
        $category = Category::factory()->create(['parent_id' => $parent->id]);
        $seats = $category->attributes()->create(['name' => 'Seats', 'slug' => 'seats', 'type' => AttributeType::Number, 'is_filterable' => true]);
        $warranty = $category->attributes()->create(['name' => 'Warranty', 'slug' => 'warranty', 'type' => AttributeType::Boolean, 'is_filterable' => true]);
        $brand = $category->attributes()->create(['name' => 'Brand', 'slug' => 'brand', 'type' => AttributeType::Text, 'is_filterable' => true]);
        $one = $this->publish(['category_id' => $category->id]);
        $two = $this->publish(['category_id' => $category->id, 'condition' => 'fair']);
        app(SyncListingAttributes::class)->handle($one, ['seats' => 3, 'warranty' => false, 'brand' => 'Acme']);
        app(SyncListingAttributes::class)->handle($two, ['seats' => 2, 'warranty' => true, 'brand' => 'Other']);
        $this->index($one, $two);
        $filters = ['category_id' => $category->id, 'province_id' => $one->province_id, 'city_id' => $one->city_id, 'condition' => 'good', 'attributes' => ['seats' => 3, 'warranty' => '0', 'brand' => 'ACME']];
        $this->assertSame([$one->id], app(SearchListings::class)->search($filters)->getCollection()->pluck('id')->all());
        $this->assertSame(2, app(SearchListings::class)->search(['category_id' => $parent->id])->total());
        $this->getJson('/search?'.http_build_query(['category_id' => $category->id, 'attributes' => ['seats' => 'oops']]))->assertUnprocessable();
        $this->assertSame(0, app(SearchListings::class)->search(['category_id' => $category->id, 'attributes' => ['brand' => 'Acme" OR price > 0 OR brand = "']])->total());
    }

    public function test_stale_index_never_exposes_withdrawn_or_deleted_listings(): void
    {
        $withdrawn = $this->publish();
        $deleted = $this->publish();
        $expired = $this->publish(['expires_at' => now()->subMinute()]);
        $future = $this->publish(['published_at' => now()->addHour()]);
        $this->index($withdrawn, $deleted, $expired, $future);
        $withdrawn->forceFill(['status' => ListingStatus::Draft])->saveQuietly();
        $deleted->deleteQuietly();
        $this->get('/search')->assertInertia(fn (Assert $page) => $page->has('listings.data', 0));
    }
}
