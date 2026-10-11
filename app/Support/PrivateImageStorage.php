<?php

declare(strict_types=1);

namespace App\Support;

class PrivateImageStorage
{
    public static function isConfigured(): bool
    {
        $disk = config('listings.images_disk');
        $settings = config('filesystems.disks.'.$disk, []);

        return ($disk === 's3' && ($settings['driver'] ?? null) === 's3'
                && ($settings['visibility'] ?? 'private') === 'private')
            || ($disk === 'listing_images' && ($settings['driver'] ?? null) === 'local'
                && ($settings['root'] ?? null) === storage_path('app/listing-images')
                && ($settings['visibility'] ?? null) === 'private'
                && empty($settings['serve']) && empty($settings['url']));
    }
}
