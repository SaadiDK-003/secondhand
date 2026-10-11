<?php

namespace Tests\Feature\Marketplace;

use App\Actions\DeleteAccount;
use App\Actions\UpdateProfilePhoto;
use App\Enums\ListingStatus;
use App\Jobs\DeleteProfilePhoto;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('listing_images');
        Queue::fake();
    }

    public function test_upload_encodes_a_small_private_webp_and_hides_storage_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/settings/profile/photo', ['photo' => UploadedFile::fake()->image('portrait.jpg', 400, 600)])
            ->assertRedirect('/settings/profile')->assertSessionHasNoErrors();
        $user->refresh();
        $bytes = Storage::disk('listing_images')->get($user->profile_photo_path);
        $dimensions = getimagesizefromstring($bytes);
        $this->assertSame([256, 256, 'image/webp'], [$dimensions[0], $dimensions[1], $dimensions['mime']]);
        $this->assertCount(1, Storage::disk('listing_images')->allFiles());
        $this->assertArrayNotHasKey('profile_photo_path', $user->toArray());
        $this->assertArrayNotHasKey('profile_photo_disk', $user->toArray());
        $this->get('/sellers/'.$user->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_replacement_and_removal_change_only_the_authenticated_users_photo(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->post('/settings/profile/photo', ['photo' => UploadedFile::fake()->image('one.png', 100, 100), 'user_id' => $other->id])->assertSessionHasNoErrors();
        $first = $user->fresh()->profile_photo_path;
        $this->post('/settings/profile/photo', ['photo' => UploadedFile::fake()->image('two.png', 100, 100)])->assertSessionHasNoErrors();
        $second = $user->fresh()->profile_photo_path;
        $this->assertNotSame($first, $second);
        Queue::assertPushed(DeleteProfilePhoto::class, fn ($job) => $job->path === $first);
        $this->assertNull($other->fresh()->profile_photo_path);
        (new DeleteProfilePhoto('listing_images', $first))->handle();
        Storage::disk('listing_images')->assertMissing($first);
        Storage::disk('listing_images')->assertExists($second);
        $this->delete('/settings/profile/photo')->assertRedirect('/settings/profile');
        $this->assertNull($user->fresh()->profile_photo_path);
        Queue::assertPushed(DeleteProfilePhoto::class, fn ($job) => $job->path === $second);
        $this->get('/sellers/'.$user->id.'/photo')->assertNotFound();
    }

    public function test_production_photo_upload_accepts_private_vps_storage(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $user = User::factory()->create();
        app(UpdateProfilePhoto::class)->handle($user, UploadedFile::fake()->image('portrait.png', 100, 100));
        $user->refresh();
        $this->assertSame('listing_images', $user->profile_photo_disk);
        $bytes = Storage::disk('listing_images')->get($user->profile_photo_path);
        $this->assertSame('image/webp', getimagesizefromstring($bytes)['mime']);
    }

    public function test_production_photo_upload_rejects_public_storage(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['listings.images_disk' => 'public']);
        Storage::fake('public');
        $user = User::factory()->create();
        try {
            app(UpdateProfilePhoto::class)->handle($user, UploadedFile::fake()->image('portrait.jpg', 100, 100));
            $this->fail('Public storage was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('photo', $exception->errors());
        }
        $this->assertNull($user->fresh()->profile_photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guests_and_invalid_files_cannot_upload(): void
    {
        $this->post('/settings/profile/photo')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;'), UploadedFile::fake()->image('wide.png', 2001, 64), UploadedFile::fake()->image('tiny.png', 32, 32), UploadedFile::fake()->image('large.png')->size(2049)] as $photo) {
            $this->post('/settings/profile/photo', ['photo' => $photo])->assertSessionHasErrors('photo');
        }
        $this->assertCount(0, Storage::disk('listing_images')->allFiles());
    }

    public function test_deleting_an_account_queues_its_current_photo_for_cleanup(): void
    {
        $user = User::factory()->create(['profile_photo_disk' => 'listing_images', 'profile_photo_path' => 'profile-photos/delete.webp']);
        app(DeleteAccount::class)->handle($user);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Queue::assertPushed(DeleteProfilePhoto::class, fn ($job) => $job->path === 'profile-photos/delete.webp');
    }

    public function test_photo_visibility_tracks_the_public_seller_and_suspension(): void
    {
        $user = User::factory()->create(['profile_photo_disk' => 'listing_images', 'profile_photo_path' => 'profile-photos/test.webp']);
        Storage::disk('listing_images')->put('profile-photos/test.webp', 'test');
        $this->get('/sellers/'.$user->id.'/photo')->assertNotFound();
        $listing = Listing::factory()->create(['user_id' => $user->id, 'status' => ListingStatus::Published, 'published_at' => now()->subDay()]);
        $this->get('/sellers/'.$user->id.'/photo')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $listing->forceFill(['status' => ListingStatus::Blocked])->save();
        $this->get('/sellers/'.$user->id.'/photo')->assertNotFound();
        $user->forceFill(['suspended_at' => now()])->save();
        $this->get('/sellers/'.$user->id.'/photo')->assertNotFound();
    }
}
