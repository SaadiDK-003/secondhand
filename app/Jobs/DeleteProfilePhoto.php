<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteProfilePhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $disk, public string $path) {}

    public function handle(): void
    {
        if (! Storage::disk($this->disk)->delete($this->path)) {
            throw new RuntimeException('Profile photo cleanup failed.');
        }
    }
}
