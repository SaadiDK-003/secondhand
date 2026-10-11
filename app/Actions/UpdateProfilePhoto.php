<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeleteProfilePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class UpdateProfilePhoto
{
    public function handle(User $user, ?UploadedFile $photo): void
    {
        $disk = config('listings.images_disk');
        $path = null;
        if ($photo) {
            if (app()->isProduction() && config('filesystems.disks.'.$disk.'.driver') !== 's3') {
                throw ValidationException::withMessages(['photo' => 'Photo storage is not available. Please try again later.']);
            }
            $bytes = $this->encode($photo);
            $path = 'profile-photos/'.Str::uuid().'.webp';
            if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
                throw new RuntimeException('Profile photo storage failed.');
            }
        }
        try {
            DB::transaction(function () use ($user, $disk, $path): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_if($locked->suspended_at !== null, 403);
                $oldDisk = $locked->profile_photo_disk;
                $oldPath = $locked->profile_photo_path;
                $locked->forceFill(['profile_photo_disk' => $path ? $disk : null, 'profile_photo_path' => $path])->save();
                if ($oldDisk && $oldPath) {
                    DeleteProfilePhoto::dispatch($oldDisk, $oldPath)->afterCommit();
                }
            });
        } catch (Throwable $exception) {
            // A queue failure after commit must not delete the newly saved photo.
            if ($path && ! User::whereKey($user->id)->where('profile_photo_path', $path)->exists()) {
                DeleteProfilePhoto::dispatch($disk, $path);
            }
            throw $exception;
        }
    }

    private function encode(UploadedFile $photo): string
    {
        $source = @imagecreatefromstring($photo->getContent());
        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'This image could not be read. Choose another photo.']);
        }
        if ($photo->getMimeType() === 'image/jpeg') {
            $metadata = @exif_read_data($photo->getPathname());
            $orientation = (int) ($metadata['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180, 6, 7 => -90, 5, 8 => 90, default => 0
            };
            if ($angle !== 0) {
                $source = imagerotate($source, $angle, 0) ?: throw new RuntimeException('Photo rotation failed.');
            }
        }
        $edge = min(imagesx($source), imagesy($source));
        $size = min(256, $edge);
        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            throw new RuntimeException('Photo allocation failed.');
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, (int) ((imagesx($source) - $edge) / 2), (int) ((imagesy($source) - $edge) / 2), $size, $size, $edge, $edge);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Photo stream failed.');
        }
        try {
            if (! imagewebp($canvas, $stream, 82)) {
                throw new RuntimeException('Photo encoding failed.');
            }
            rewind($stream);

            return stream_get_contents($stream) ?: throw new RuntimeException('Photo encoding was empty.');
        } finally {
            fclose($stream);
        }
    }
}
