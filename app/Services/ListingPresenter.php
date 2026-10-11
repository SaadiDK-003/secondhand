<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingImage;

class ListingPresenter
{
    /** @return array<string, mixed> */
    public function card(Listing $listing, bool $favorite = false): array
    {
        $image = $listing->images->firstWhere('status', 'ready');

        return [
            'id' => $listing->id, 'slug' => $listing->slug, 'title' => $listing->title,
            'price' => $listing->price, 'city' => $listing->city->name, 'area' => $listing->area?->name,
            'category' => $listing->category->name, 'published_at' => $listing->published_at?->toIso8601String(),
            'image_url' => $image ? route('listing-images.show', [$image, 'card']) : null, 'is_favorite' => $favorite,
            'image_srcset' => $image ? $this->srcset($image) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Listing $listing): array
    {
        return [
            'id' => $listing->id, 'slug' => $listing->slug, 'title' => $listing->title,
            'price' => $listing->price, 'status' => $listing->status->value,
            'city' => $listing->city->name, 'category' => $listing->category->name,
            'images' => $listing->images->map(fn (ListingImage $image) => [
                'id' => $image->id, 'status' => $image->status,
                'card_url' => $image->status === 'ready' ? route('listing-images.show', [$image, 'card']) : null,
                'large_url' => $image->status === 'ready' ? route('listing-images.show', [$image, 'large']) : null,
                'thumbnail_url' => $image->status === 'ready' ? route('listing-images.show', [$image, 'thumbnail']) : null,
                'srcset' => $this->srcset($image),
            ])->all(),
        ];
    }

    private function srcset(ListingImage $image): ?string
    {
        if ($image->status !== 'ready' || ! $image->width || ! $image->height) {
            return null;
        }
        $sources = [];
        foreach (config('listings.variants') as $name => [$maxWidth, $maxHeight]) {
            $ratio = min($maxWidth / $image->width, $maxHeight / $image->height, 1);
            $width = max(1, (int) round($image->width * $ratio));
            // Portrait photos and small originals have different actual widths.
            // Deduplicate sizes when several variants reach the original width.
            $sources[$width] ??= route('listing-images.show', [$image, $name]).' '.$width.'w';
        }

        return implode(', ', $sources);
    }

    /** @return array<string, mixed> */
    public function detail(Listing $listing): array
    {
        return [...$this->summary($listing),
            'description' => $listing->description, 'condition' => $listing->condition->value,
            'is_negotiable' => $listing->is_negotiable, 'category_id' => $listing->category_id,
            'province_id' => $listing->province_id, 'city_id' => $listing->city_id, 'area_id' => $listing->area_id,
            'province' => $listing->province->name, 'area' => $listing->area?->name,
            'published_at' => $listing->published_at?->toIso8601String(),
            'seller' => ['name' => $listing->user->name, 'joined' => $listing->user->created_at?->format('M Y'), 'url' => '/sellers/'.$listing->user_id],
            'attributes' => $listing->attributeValues->mapWithKeys(fn ($value) => [$value->attribute->slug => $value->value])->all(),
            'details' => $listing->attributeValues->map(function ($value) {
                $raw = $value->value;
                $options = $value->attribute->options->pluck('label', 'value');
                $formatted = is_bool($raw) ? ($raw ? 'Yes' : 'No')
                    : (is_array($raw) ? implode(', ', array_map(fn ($option) => $options->get($option, $option), $raw)) : $options->get((string) $raw, (string) $raw));

                return ['name' => $value->attribute->name, 'value' => $formatted];
            })->all(),
        ];
    }
}
