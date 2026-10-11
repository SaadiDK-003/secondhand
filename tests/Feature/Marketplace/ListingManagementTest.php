<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\AttributeType;
use App\Enums\ListingStatus;
use App\Jobs\DeleteListingImageFiles;
use App\Jobs\ProcessListingImage;
use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ListingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('listing_images');
        Queue::fake();
    }

    public function test_guest_cannot_open_selling_form_or_post_an_ad(): void
    {
        $this->get('/sell')->assertRedirect('/login');
        $this->post('/listings', [])->assertRedirect('/login');
    }

    public function test_create_ad_uses_authenticated_owner_and_queues_private_photos(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post('/listings', [...$this->payload(), 'user_id' => $other->id, 'status' => 'published'])
            ->assertSessionHasNoErrors();
        $listing = Listing::sole();
        $this->assertSame($owner->id, $listing->user_id);
        $this->assertSame(ListingStatus::Draft, $listing->status);
        $image = $listing->images()->sole();
        Storage::disk('listing_images')->assertExists($image->original_path);
        Queue::assertPushed(ProcessListingImage::class, fn ($job) => $job->imageId === $image->id);
        $this->get('/listings/'.$listing->id.'/preview')->assertOk()->assertInertia(fn (Assert $page) => $page->component('listings/Show')->where('preview', true)->where('listing.images.0.status', 'pending'));
        $this->get('/ad/'.$listing->slug)->assertNotFound();
    }

    public function test_my_listings_only_contains_the_signed_in_users_ads(): void
    {
        $own = Listing::factory()->create();
        Listing::factory()->create();
        $this->actingAs($own->user)->get('/my-listings')->assertInertia(fn (Assert $page) => $page->component('listings/Index')->has('listings.data', 1)->where('listings.data.0.id', $own->id));
    }

    public function test_other_users_cannot_edit_preview_delete_or_submit_an_ad(): void
    {
        $listing = Listing::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->get('/listings/'.$listing->id.'/edit')->assertForbidden();
        $this->get('/listings/'.$listing->id.'/preview')->assertForbidden();
        $this->patch('/listings/'.$listing->id, $this->payload())->assertForbidden();
        $this->delete('/listings/'.$listing->id)->assertForbidden();
        $this->post('/listings/'.$listing->id.'/submit')->assertForbidden();
        $this->assertModelExists($listing);
    }

    public function test_editing_a_published_ad_requires_review_again(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subDay()]);
        $this->actingAs($listing->user)->patch('/listings/'.$listing->id, $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(ListingStatus::Draft, $listing->fresh()->status);
        $this->assertNull($listing->fresh()->published_at);
        $this->get('/ad/'.$listing->slug)->assertNotFound();
    }

    public function test_invalid_dynamic_attributes_roll_back_listing_and_uploaded_files(): void
    {
        $payload = $this->payload();
        Category::findOrFail($payload['category_id'])->attributes()->create(['name' => 'Brand', 'slug' => 'brand', 'type' => AttributeType::Text, 'is_required' => true]);
        $this->actingAs(User::factory()->create())->post('/listings', $payload)->assertSessionHasErrors('attributes.brand');
        $this->assertDatabaseCount('listings', 0);
        $this->assertSame([], Storage::disk('listing_images')->allFiles());
    }

    public function test_missing_and_excess_photos_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $payload['images'] = [];
        $this->post('/listings', $payload)->assertSessionHasErrors('images');
        $payload['images'] = array_map(fn () => UploadedFile::fake()->image('photo.jpg', 20, 20), range(1, 13));
        $this->post('/listings', $payload)->assertSessionHasErrors('images');
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_uploads_validate_real_content_extension_size_and_pixel_count(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;'), UploadedFile::fake()->image('photo.jpg')->size(10241), UploadedFile::fake()->image('wide.jpg', 8001, 1), UploadedFile::fake()->createWithContent('photo.png', UploadedFile::fake()->image('photo.jpg')->get())] as $upload) {
            $payload = $this->payload();
            $payload['images'] = [$upload];
            $this->post('/listings', $payload)->assertSessionHasErrors('images.0');
        }
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_category_and_location_validation_is_server_side(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        Category::findOrFail($payload['category_id'])->update(['is_active' => false]);
        $payload['city_id'] = City::factory()->create()->id;
        $this->post('/listings', $payload)->assertSessionHasErrors(['category_id', 'city_id']);
    }

    public function test_retained_photos_count_towards_the_limit_and_cannot_be_stolen(): void
    {
        $listing = Listing::factory()->create();
        $this->actingAs($listing->user);
        foreach (range(1, 12) as $index) {
            $listing->images()->create(['disk' => 'listing_images', 'directory' => 'existing/'.$index, 'original_path' => 'existing/'.$index.'/original']);
        }
        $this->patch('/listings/'.$listing->id, $this->payload())->assertSessionHasErrors('images');
        $other = Listing::factory()->create()->images()->create(['disk' => 'listing_images', 'directory' => 'other', 'original_path' => 'other/original']);
        $this->patch('/listings/'.$listing->id, [...$this->payload(), 'remove_image_ids' => [$other->id]])->assertSessionHasErrors('remove_image_ids.0');
        $this->assertSame(12, $listing->images()->count());
    }

    public function test_only_ready_photos_can_be_submitted_and_submission_stays_pending(): void
    {
        $this->actingAs(User::factory()->create())->post('/listings', $this->payload())->assertSessionHasNoErrors();
        $listing = Listing::sole();
        $this->post('/listings/'.$listing->id.'/submit')->assertSessionHasErrors('images');
        $listing->images()->update(['status' => 'ready']);
        $this->post('/listings/'.$listing->id.'/submit')->assertSessionHasNoErrors()->assertRedirect('/listings/'.$listing->id.'/preview');
        $this->assertSame(ListingStatus::Pending, $listing->fresh()->status);
        $this->get('/ad/'.$listing->slug)->assertNotFound();
        $this->post('/listings/'.$listing->id.'/submit')->assertForbidden();
    }

    public function test_delete_queues_cleanup_and_removes_listing(): void
    {
        $this->actingAs(User::factory()->create())->post('/listings', $this->payload());
        $listing = Listing::sole();
        $image = $listing->images()->sole();
        $this->delete('/listings/'.$listing->id)->assertRedirect('/my-listings');
        $this->assertModelMissing($listing);
        $this->assertDatabaseCount('listing_images', 0);
        Queue::assertPushed(DeleteListingImageFiles::class, fn ($job) => $job->directory === $image->directory);
    }

    public function test_account_deletion_also_queues_photo_cleanup(): void
    {
        $this->actingAs(User::factory()->create())->post('/listings', $this->payload());
        $this->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertDatabaseCount('users', 0);
        Queue::assertPushed(DeleteListingImageFiles::class);
        $this->assertDatabaseCount('listings', 0);
        $this->assertDatabaseCount('listing_images', 0);
    }

    public function test_public_detail_never_exposes_seller_email_or_mobile(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subDay()]);
        $this->get('/ad/'.$listing->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('listings/Show')->where('preview', false)->where('listing.seller.name', $listing->user->name)->missing('listing.seller.email')->missing('listing.seller.mobile_number')->missing('listing.user'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $city = City::factory()->create();

        return ['category_id' => Category::factory()->create()->id, 'title' => 'Used wooden side table', 'description' => 'A solid wooden table with light signs of use.', 'price' => '4500.00', 'condition' => 'good', 'is_negotiable' => false, 'province_id' => $city->province_id, 'city_id' => $city->id, 'area_id' => null, 'attributes' => [], 'images' => [UploadedFile::fake()->image('table.jpg', 300, 225)]];
    }
}
