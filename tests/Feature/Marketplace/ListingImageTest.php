<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Actions\SaveListing;
use App\Enums\ListingStatus;
use App\Jobs\DeleteListingImageFiles;
use App\Jobs\ProcessListingImage;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use App\Services\ListingImageProcessor;
use App\Services\ListingPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ListingImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('listing_images');
    }

    /** @return array<string, array{string}> */
    public static function uploadFormats(): array
    {
        return ['JPEG' => ['jpg'], 'PNG' => ['png'], 'WebP' => ['webp']];
    }

    #[DataProvider('uploadFormats')]
    public function test_job_makes_webp_variants_preserves_aspect_ratio_and_removes_original(string $extension): void
    {
        $image = $this->photo(2000, 1000, $extension);
        (new ProcessListingImage($image->id))->handle(app(ListingImageProcessor::class));
        $this->assertSame('ready', $image->fresh()->status);
        $this->assertNull($image->fresh()->original_path);
        Storage::disk('listing_images')->assertMissing('photo/original');
        foreach (['thumbnail' => [300, 150], 'card' => [600, 300], 'medium' => [1000, 500], 'large' => [1600, 800]] as $variant => [$width, $height]) {
            $info = getimagesizefromstring(Storage::disk('listing_images')->get('photo/'.$variant.'.webp'));
            $this->assertSame([$width, $height, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        }
        (new ProcessListingImage($image->id))->handle(app(ListingImageProcessor::class));
        $this->assertSame(4, count(Storage::disk('listing_images')->allFiles()));
    }

    public function test_transparent_png_keeps_transparency_in_webp(): void
    {
        $image = $this->photo(80, 60, 'png');
        $canvas = imagecreatetruecolor(80, 60);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 0, 0, 127));
        ob_start();
        imagepng($canvas);
        Storage::disk('listing_images')->put('photo/original', ob_get_clean());
        app(ListingImageProcessor::class)->process($image);
        $decoded = imagecreatefromstring(Storage::disk('listing_images')->get('photo/card.webp'));
        $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
    }

    public function test_responsive_sources_use_actual_portrait_widths(): void
    {
        $image = $this->photo(1000, 2000);
        app(ListingImageProcessor::class)->process($image);
        $presenter = app(ListingPresenter::class);
        $listing = $image->listing->load(['city', 'area', 'category', 'images']);
        $sources = $presenter->card($listing)['image_srcset'];
        preg_match_all('/ (\d+)w/', $sources, $matches);
        $this->assertSame(['113', '225', '375', '600'], $matches[1]);
        foreach (['thumbnail', 'card', 'medium', 'large'] as $index => $variant) {
            $info = getimagesizefromstring(Storage::disk('listing_images')->get('photo/'.$variant.'.webp'));
            $this->assertSame((int) $matches[1][$index], $info[0]);
        }
        $this->assertStringNotContainsString('photo/', $sources);
    }

    public function test_responsive_sources_deduplicate_small_images(): void
    {
        $small = $this->photo(80, 60);
        app(ListingImageProcessor::class)->process($small);
        $summary = app(ListingPresenter::class)->summary($small->listing->load(['city', 'category', 'images']));
        $this->assertSame(route('listing-images.show', [$small, 'thumbnail']).' 80w', $summary['images'][0]['srcset']);
    }

    public function test_small_photos_are_not_upscaled(): void
    {
        $image = $this->photo(80, 60);
        (new ProcessListingImage($image->id))->handle(app(ListingImageProcessor::class));
        $size = getimagesizefromstring(Storage::disk('listing_images')->get('photo/large.webp'));
        $this->assertSame([80, 60], [$size[0], $size[1]]);
    }

    public function test_photo_routes_protect_drafts_and_never_serve_originals(): void
    {
        $image = $this->photo(100, 100);
        (new ProcessListingImage($image->id))->handle(app(ListingImageProcessor::class));
        $this->get('/listing-images/'.$image->id.'/card')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/listing-images/'.$image->id.'/card')->assertForbidden();
        $this->actingAs($image->listing->user)->get('/listing-images/'.$image->id.'/card')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/listing-images/'.$image->id.'/original')->assertNotFound();
        $image->listing->forceFill(['status' => ListingStatus::Published, 'published_at' => now()->subMinute()])->save();
        $this->app['auth']->forgetGuards();
        $this->get('/listing-images/'.$image->id.'/card')->assertOk();
    }

    public function test_missing_original_fails_safely_and_cleanup_is_idempotent(): void
    {
        $image = $this->photo(100, 100);
        Storage::disk('listing_images')->delete('photo/original');
        $job = new ProcessListingImage($image->id);
        try {
            $job->handle(app(ListingImageProcessor::class));
            $this->fail('Missing original was accepted.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }
        $this->assertSame('failed', $image->fresh()->status);
        $cleanup = new DeleteListingImageFiles($image->id, 'listing_images', 'photo');
        $cleanup->handle();
        $cleanup->handle();
        $this->assertSame([], Storage::disk('listing_images')->allFiles());
    }

    private function photo(int $width, int $height, string $extension = 'jpg'): ListingImage
    {
        Storage::disk('listing_images')->put('photo/original', UploadedFile::fake()->image('photo.'.$extension, $width, $height)->get());

        return Listing::factory()->create()->images()->create(['disk' => 'listing_images', 'directory' => 'photo', 'original_path' => 'photo/original']);
    }

    public function test_production_listing_upload_uses_private_vps_storage_and_webp_processing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Queue::fake();
        $listing = Listing::factory()->create();
        $saved = app(SaveListing::class)->handle($listing->user, $listing->getAttributes(),
            [UploadedFile::fake()->image('item.jpg', 100, 100)], [], $listing);
        $image = $saved->images()->firstOrFail();
        $this->assertSame('listing_images', $image->disk);
        Queue::assertPushed(ProcessListingImage::class);
        $original = $image->original_path;
        app(ListingImageProcessor::class)->process($image);
        $image->refresh();
        $this->assertSame('image/webp', getimagesizefromstring(Storage::disk($image->disk)->get($image->directory.'/large.webp'))['mime']);
        Storage::disk($image->disk)->assertMissing($original);
    }

    public function test_production_listing_upload_rejects_storage_inside_public(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['filesystems.disks.listing_images.root' => public_path('images')]);
        $listing = Listing::factory()->create();
        $this->expectException(RuntimeException::class);
        app(SaveListing::class)->handle($listing->user, $listing->getAttributes(),
            [UploadedFile::fake()->image('item.png', 100, 100)], [], $listing);
    }
}
