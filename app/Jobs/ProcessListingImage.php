<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ListingImage;
use App\Services\ListingImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessListingImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $imageId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('listing-image-'.$this->imageId))->shared()->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(ListingImageProcessor $processor): void
    {
        $image = ListingImage::find($this->imageId);
        if (! $image) {
            return;
        }
        if ($image->status === 'ready') {
            if ($image->original_path !== null) {
                if (! Storage::disk($image->disk)->delete($image->original_path)) {
                    throw new \RuntimeException('Original image cleanup failed.');
                }
                $image->forceFill(['original_path' => null])->save();
            }

            return;
        }
        $image->forceFill(['status' => 'processing'])->save();
        $processor->process($image);
    }

    public function failed(?Throwable $exception): void
    {
        ListingImage::whereKey($this->imageId)->where('status', '!=', 'ready')->update(['status' => 'failed']);
        Log::warning('Listing image processing failed', ['image_id' => $this->imageId]);
    }
}
