<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Services\MarketplaceOptions;
use App\Services\SeoMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Ssr\SsrState;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private function published(array $state = []): Listing
    {
        return Listing::factory()->create([...['status' => ListingStatus::Published, 'published_at' => now()->subHour()], ...$state]);
    }

    public function test_public_metadata_has_trusted_canonical_schema_and_no_private_contact_fields(): void
    {
        config(['app.url' => 'https://second-hand.example']);
        $listing = $this->published(['title' => 'Sofa </script><script>alert(1)</script>']);
        $this->get('/ad/'.$listing->slug.'?tracking=test')->assertOk()->assertInertia(function (Assert $p) use ($listing): void {
            $p->where('seo.canonical', 'https://second-hand.example/ad/'.$listing->slug)->where('seo.robots', 'index, follow')->where('seo.schema', function ($json) use ($listing): bool {
                $schema = json_decode($json, true);

                return $schema[0]['@type'] === 'Product' && $schema[0]['name'] === $listing->title && $schema[0]['offers']['priceCurrency'] === 'PKR' && ! str_contains($json, '</script>') && ! str_contains($json, $listing->user->email) && ! str_contains($json, $listing->user->mobile_number);
            });
        });
    }

    public function test_metadata_is_present_in_html_when_ssr_is_unavailable(): void
    {
        $listing = $this->published();
        $html = $this->get('/ad/'.$listing->slug)->assertOk()->getContent();
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('property="og:title"', $html);
    }

    public function test_private_previews_are_noindex_and_have_no_product_schema(): void
    {
        $listing = Listing::factory()->create();
        $this->actingAs($listing->user)->get('/listings/'.$listing->id.'/preview')->assertInertia(fn (Assert $p) => $p->where('seo.robots', 'noindex, follow')->where('seo.schema', '[]'));
        $this->get('/login')->assertRedirect();
    }

    public function test_city_and_category_pages_filter_public_ads_and_have_stable_canonicals(): void
    {
        $one = $this->published();
        $this->published();
        $private = Listing::factory()->create(['city_id' => $one->city_id, 'province_id' => $one->province_id, 'category_id' => $one->category_id]);
        $this->get('/browse/'.$one->city->slug.'/'.$one->category->slug.'?utm_source=test')->assertOk()->assertInertia(fn (Assert $p) => $p->has('listings.data', 1)->where('listings.data.0.id', $one->id)->where('seo.canonical', app(SeoMetadata::class)->url('browse/'.$one->city->slug.'/'.$one->category->slug)));
        $parent = Category::factory()->create();
        $one->category->update(['parent_id' => $parent->id]);
        $this->get('/category/'.$parent->slug)->assertOk()->assertInertia(fn (Assert $p) => $p->has('listings.data', 1));
    }

    public function test_empty_and_out_of_range_landing_pages_are_not_indexed(): void
    {
        $city = City::factory()->create();
        $this->get('/browse/'.$city->slug)->assertOk()->assertInertia(fn (Assert $p) => $p->where('seo.robots', 'noindex, follow'));
        $this->get('/browse/'.$city->slug.'?page=2')->assertNotFound();
        $category = Category::factory()->create(['is_active' => false]);
        $this->get('/category/'.$category->slug)->assertNotFound();
    }

    public function test_sitemaps_include_only_current_public_ads_and_escape_urls(): void
    {
        config(['app.url' => 'https://second-hand.example']);
        $one = $this->published();
        $private = Listing::factory()->create();
        $expired = $this->published(['expires_at' => now()->subSecond()]);
        $future = $this->published(['published_at' => now()->addDay()]);
        $suspended = $this->published();
        $suspended->user->forceFill(['suspended_at' => now()])->save();
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://second-hand.example/sitemaps/listings/1.xml', false);
        $response = $this->get('/sitemaps/listings/1.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertCount(1, $xml->url);
        $response->assertSee($one->slug)->assertDontSee($private->slug)->assertDontSee($expired->slug)->assertDontSee($future->slug)->assertDontSee($suspended->slug);
        $this->get('/sitemaps/pages.xml')->assertSee('/browse/'.$one->city->slug)->assertSee('/category/'.$one->category->slug);
        $one->forceFill(['status' => ListingStatus::Blocked])->save();
        $this->get('/sitemaps/listings/1.xml')->assertDontSee($one->slug);
        $this->get('/sitemaps/listings/0.xml')->assertNotFound();
        $this->get('/sitemaps/listings/999.xml')->assertNotFound();
    }

    public function test_robots_points_to_canonical_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.app(SeoMetadata::class)->url('sitemap.xml'), false)->assertSee('Disallow: /admin', false);
    }

    public function test_reference_cache_avoids_repeat_queries_and_refreshes_after_taxonomy_edits(): void
    {
        $category = Category::factory()->create();
        $service = app(MarketplaceOptions::class);
        DB::enableQueryLog();
        $service->discovery();
        $cold = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->discovery();
        $warm = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertGreaterThan(0, $cold);
        $this->assertSame(0, $warm);
        $category->update(['name' => 'Updated category']);
        $this->assertSame('Updated category', collect($service->discovery()['categories'])->firstWhere('id', $category->id)['name']);
    }

    public function test_reference_options_survive_restricted_file_cache_serialization(): void
    {
        config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/reference-options-test')]);
        MarketplaceOptions::clear();
        try {
            Category::factory()->create();
            $service = app(MarketplaceOptions::class);
            $cold = $service->discovery();
            $warm = $service->discovery();
            $this->assertSame($cold, $warm);
            $this->assertIsArray($warm['categories']);
            $this->assertIsArray($warm['categories'][0]);
            $this->assertIsArray($warm['provinces']);
        } finally {
            MarketplaceOptions::clear();
        }
    }

    public function test_live_ssr_delivers_page_content_and_schema_before_javascript(): void
    {
        if (! getenv('SSR_TESTS')) {
            $this->markTestSkipped('Set SSR_TESTS=1 with the SSR service running.');
        }
        config(['inertia.ssr.enabled' => true, 'inertia.ssr.throw_on_error' => true]);
        $listing = $this->published(['title' => 'Server rendered sofa']);
        $html = $this->get('/ad/'.$listing->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Server rendered sofa\s*<\/h1>/', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('data-server-rendered="true"', $html);
        preg_match('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        $structured = json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Product', $structured[0]['@type']);
        $this->assertSame('Server rendered sofa', $structured[0]['name']);
        $this->app->forgetInstance(SsrState::class);
        $this->get('/')->assertOk()->assertSee('Browse categories', false);
    }
}
