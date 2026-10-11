<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteListingImageFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 60;

    public function __construct(public int $imageId, public string $disk, public string $directory) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('listing-image-'.$this->imageId))->shared()->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(): void
    {
        if (! Storage::disk($this->disk)->deleteDirectory($this->directory)) {
            throw new RuntimeException('Image cleanup failed.');
        }
    }
}
