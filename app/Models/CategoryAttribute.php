<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property AttributeType $type */
#[Fillable(['category_id', 'name', 'slug', 'type', 'is_required', 'is_filterable', 'sort_order'])]
class CategoryAttribute extends Model
{
    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<CategoryAttributeOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(CategoryAttributeOption::class);
    }

    /** @return HasMany<ListingAttributeValue, $this> */
    public function listingValues(): HasMany
    {
        return $this->hasMany(ListingAttributeValue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
        ];
    }
}
