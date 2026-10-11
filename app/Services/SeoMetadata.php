<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Support\Str;

class SeoMetadata
{
    public function url(string $path = ''): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /** @param list<array<string,mixed>> $schema
     * @return array<string,mixed> */
    public function page(string $title, string $description, string $path, bool $index = true, array $schema = [], ?string $image = null): array
    {
        return ['title' => $title, 'description' => $description, 'canonical' => $this->url($path), 'robots' => $index ? 'index, follow' : 'noindex, follow', 'image' => $image, 'schema' => json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)];
    }

    /** @return array<string,mixed> */
    public function home(): array
    {
        return $this->page('Buy and sell used items in Pakistan', 'Find pre-owned furniture, phones, appliances and more across Pakistan. Give good things a second home.', '', true, [['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Second Hand', 'url' => $this->url()]]);
    }

    /** @return array<string,mixed> */
    public function listing(Listing $listing): array
    {
        $url = $this->url('ad/'.$listing->slug);
        $description = Str::limit(preg_replace('/\s+/u', ' ', strip_tags($listing->description)) ?? '', 155);
        $image = $listing->images->firstWhere('status', 'ready');
        $imageUrl = $image ? $this->url('listing-images/'.$image->id.'/large') : null;
        $breadcrumbs = [['name' => 'Home', 'url' => $this->url()], ['name' => $listing->city->name, 'url' => $this->url('browse/'.$listing->city->slug)], ['name' => $listing->category->name, 'url' => $this->url('browse/'.$listing->city->slug.'/'.$listing->category->slug)], ['name' => $listing->title, 'url' => $url]];
        $product = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $listing->title, 'description' => $description, 'url' => $url, 'itemCondition' => 'https://schema.org/UsedCondition', 'offers' => ['@type' => 'Offer', 'price' => $listing->price, 'priceCurrency' => 'PKR', 'availability' => 'https://schema.org/InStock', 'url' => $url, 'itemCondition' => 'https://schema.org/UsedCondition']];
        if ($imageUrl) {
            $product['image'] = [$imageUrl];
        }
        if ($listing->expires_at) {
            $product['offers']['priceValidUntil'] = $listing->expires_at->toDateString();
        }
        $schema = [$product, ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($crumb, $position) => ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $crumb['name'], 'item' => $crumb['url']], $breadcrumbs, array_keys($breadcrumbs))]];

        return [...$this->page($listing->title.' in '.$listing->city->name, $description, 'ad/'.$listing->slug, true, $schema, $imageUrl), 'breadcrumbs' => $breadcrumbs];
    }
}
