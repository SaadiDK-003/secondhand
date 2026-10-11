<?php

declare(strict_types=1);

use App\Models\Listing;

return [
    'driver' => env('SCOUT_DRIVER', 'meilisearch'),
    'prefix' => env('SCOUT_PREFIX', ''),
    'queue' => true,
    'after_commit' => true,
    'chunk' => ['searchable' => 500, 'unsearchable' => 500],
    'soft_delete' => false,
    'identify' => false,
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [Listing::class => [
            'searchableAttributes' => ['title', 'category_name', 'description', 'attribute_text'],
            'filterableAttributes' => ['category_ids', 'province_id', 'city_id', 'area_id', 'condition', 'price', 'published_at', 'expires_at', 'attributes'],
            'sortableAttributes' => ['price', 'published_at', 'id'],
            'displayedAttributes' => ['id'],
            'pagination' => ['maxTotalHits' => 1000],
        ]],
    ],
];
