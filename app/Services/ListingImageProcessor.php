<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ListingImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListingImageProcessor
{
    public function process(ListingImage $image): void
    {
        $disk = Storage::disk($image->disk);
        $sourcePath = $image->original_path ?? throw new RuntimeException('Original image is missing.');
        $bytes = $disk->get($sourcePath);
        if ($bytes === null) {
            throw new RuntimeException('Original image could not be read.');
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > 20000000 || $size[0] > 8000 || $size[1] > 8000) {
            throw new RuntimeException('Invalid image dimensions.');
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('Image could not be decoded.');
        }
        // Read orientation only; freshly encoded WebP files contain no original EXIF metadata.
        if ($size['mime'] === 'image/jpeg') {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to open image metadata stream.');
            }
            try {
                fwrite($stream, $bytes);
                rewind($stream);
                $exif = @exif_read_data($stream);
                $orientation = (int) ($exif['Orientation'] ?? 1);
            } finally {
                fclose($stream);
            }
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180, 6, 7 => -90, 5, 8 => 90, default => 0
            };
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                if ($rotated === false) {
                    throw new RuntimeException('Image rotation failed.');
                }
                $source = $rotated;
            }
        }
        $width = imagesx($source);
        $height = imagesy($source);
        foreach (config('listings.variants') as $name => [$maxWidth, $maxHeight]) {
            $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
            $outWidth = max(1, (int) round($width * $ratio));
            $outHeight = max(1, (int) round($height * $ratio));
            $canvas = imagecreatetruecolor($outWidth, $outHeight);
            if ($canvas === false) {
                throw new RuntimeException('Unable to allocate image.');
            }
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to open image output stream.');
            }
            try {
                if (! imagewebp($canvas, $stream, 82)) {
                    throw new RuntimeException('Image encoding failed.');
                }
                rewind($stream);
                if (! $disk->put($image->directory.'/'.$name.'.webp', $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Image storage failed.');
                }
            } finally {
                fclose($stream);
            }
            unset($canvas);
        }
        $image->forceFill(['status' => 'ready', 'width' => $width, 'height' => $height])->save();
        // Delete the private original only after every variant is stored.
        if (! $disk->delete($sourcePath)) {
            throw new RuntimeException('Original image cleanup failed.');
        }
        $image->forceFill(['original_path' => null])->save();
    }
}
