<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Province;
use Illuminate\Support\Facades\Cache;

class MarketplaceOptions
{
    /** @return array<string,mixed> */
    public function discovery(): array
    {
        return Cache::remember('marketplace.discovery-options.v2', 300, fn () => [
            'categories' => Category::where('is_active', true)->with(['attributes' => fn ($q) => $q->where('is_filterable', true)->with('options')])->orderBy('sort_order')->get()->toArray(),
            'provinces' => Province::with('cities.areas')->orderBy('name')->get()->toArray(),
        ]);
    }

    public static function clear(): void
    {
        Cache::forget('marketplace.discovery-options.v2');
    }
}
