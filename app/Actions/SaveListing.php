<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ListingStatus;
use App\Jobs\DeleteListingImageFiles;
use App\Jobs\ProcessListingImage;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SaveListing
{
    public function __construct(private SyncListingAttributes $attributes) {}

    /** @param array<string, mixed> $data Validated base fields and attributes.
     * @param  list<UploadedFile>  $uploads
     * @param  list<int>  $removeIds
     */
    public function handle(User $user, array $data, array $uploads, array $removeIds = [], ?Listing $listing = null): Listing
    {
        $disk = config('listings.images_disk');
        if (app()->isProduction() && config('filesystems.disks.'.$disk.'.driver') !== 's3') {
            throw new RuntimeException('Production listing images require private S3/R2 storage.');
        }
        $staged = [];
        try {
            foreach ($uploads as $upload) {
                $directory = 'listings/'.Str::uuid();
                $path = $upload->storeAs($directory, 'original', ['disk' => $disk, 'visibility' => 'private']);
                if ($path === false) {
                    throw new RuntimeException('Photo upload failed.');
                }
                $staged[] = ['disk' => $disk, 'directory' => $directory, 'original_path' => $path];
            }

            return DB::transaction(function () use ($user, $data, $removeIds, $listing, $staged): Listing {
                $saved = $listing ? Listing::query()->lockForUpdate()->findOrFail($listing->id) : new Listing;
                Gate::forUser($user)->authorize($listing ? 'update' : 'create', $listing ? $saved : Listing::class);
                $existingCount = $listing ? $saved->images()->count() : 0;
                $removed = $listing ? $saved->images()->whereIn('id', $removeIds)->get() : collect();
                if ($removed->count() !== count($removeIds)) {
                    throw ValidationException::withMessages(['remove_image_ids' => 'A photo does not belong to this listing.']);
                }
                $count = $existingCount - $removed->count() + count($staged);
                if ($count < 1 || $count > 12) {
                    throw ValidationException::withMessages(['images' => 'Keep between 1 and 12 photos.']);
                }
                $fields = Arr::only($data, ['category_id', 'title', 'description', 'price', 'is_negotiable', 'condition', 'province_id', 'city_id', 'area_id']);
                if ($listing && $saved->category_id !== (int) $fields['category_id']) {
                    $saved->attributeValues()->delete();
                }
                $saved->fill($fields);
                $saved->user()->associate($user);
                // Any edit to a published ad must pass moderation again.
                $saved->forceFill(['status' => ListingStatus::Draft, 'published_at' => null, 'expires_at' => null]);
                $saved->save();
                $this->attributes->handle($saved, $data['attributes'] ?? []);
                foreach ($removed as $image) {
                    $image->delete();
                    DeleteListingImageFiles::dispatch($image->id, $image->disk, $image->directory)->afterCommit();
                }
                $order = ($saved->images()->max('sort_order') ?? -1) + 1;
                foreach ($staged as $photo) {
                    $image = $saved->images()->create([...$photo, 'sort_order' => $order++]);
                    ProcessListingImage::dispatch($image->id)->afterCommit();
                }

                return $saved;
            });
        } catch (Throwable $exception) {
            foreach ($staged as $photo) {
                if (! ListingImage::where('directory', $photo['directory'])->exists()) {
                    Storage::disk($photo['disk'])->deleteDirectory($photo['directory']);
                }
            }
            throw $exception;
        }
    }
}
