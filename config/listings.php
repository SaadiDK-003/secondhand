<?php

declare(strict_types=1);

return [
    'expiry_days' => max(1, (int) env('LISTING_EXPIRY_DAYS', 30)),
    'images_disk' => env('LISTING_IMAGES_DISK', 'listing_images'),
    'max_images' => 12,
    'max_upload_kb' => 10240,
    'variants' => [
        'thumbnail' => [300, 225],
        'card' => [600, 450],
        'medium' => [1000, 750],
        'large' => [1600, 1200],
    ],
];
