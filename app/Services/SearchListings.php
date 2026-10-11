<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Meilisearch\Endpoints\Indexes;

class SearchListings
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Listing>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $clauses = ['published_at <= '.now()->timestamp, 'expires_at > '.now()->timestamp];
        foreach (['category_id' => 'category_ids', 'province_id' => 'province_id', 'city_id' => 'city_id', 'area_id' => 'area_id'] as $key => $field) {
            if (! empty($filters[$key])) {
                $clauses[] = $field.' = '.(int) $filters[$key];
            }
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $clauses[] = 'price '.$operator.' '.(float) $filters[$key];
            }
        }
        if (! empty($filters['condition'])) {
            $clauses[] = 'condition = '.json_encode($filters['condition'], JSON_THROW_ON_ERROR);
        }
        $category = isset($filters['category_id']) ? Category::whereKey($filters['category_id'])->first() : null;
        foreach ($category?->attributes()->where('is_filterable', true)->get() ?? [] as $attribute) {
            $value = $filters['attributes'][$attribute->slug] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $value = match ($attribute->type) {
                AttributeType::Boolean => (bool) $value,
                AttributeType::Number => (float) $value,
                default => is_string($value) ? mb_strtolower($value) : $value,
            };
            $clauses[] = 'attributes.a_'.$attribute->id.(is_array($value) ? ' IN ' : ' = ').json_encode($value, JSON_THROW_ON_ERROR);
        }
        $sort = match ($filters['sort'] ?? 'relevant') {
            'newest' => ['published_at:desc', 'id:desc'],
            'price_asc' => ['price:asc', 'id:desc'],
            'price_desc' => ['price:desc', 'id:desc'],
            default => empty($filters['q']) ? ['published_at:desc', 'id:desc'] : [],
        };

        $results = Listing::search($filters['q'] ?? '', function (Indexes $index, string $query, array $options) use ($clauses, $sort) {
            return $index->search($query, [...$options, 'filter' => $clauses, 'sort' => $sort, 'attributesToRetrieve' => ['id']]);
        })->query(fn (Builder $query) => $query->whereIn('id', Listing::published()->select('id'))->with(['category', 'city', 'area', 'images']))
            ->paginate(20, 'page', (int) ($filters['page'] ?? 1));
        if (! $results instanceof LengthAwarePaginator) {
            throw new \LogicException('Search engine must support length-aware pagination.');
        }

        return $results->withQueryString();
    }
}
