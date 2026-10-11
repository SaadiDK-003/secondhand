<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string|int|float|bool|list<string> $value */
#[Fillable(['category_attribute_id', 'category_id', 'value'])]
class ListingAttributeValue extends Model
{
    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @return BelongsTo<CategoryAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class, 'category_attribute_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
