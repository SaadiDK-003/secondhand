<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * @property ListingStatus $status
 * @property ListingCondition $condition
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $sold_at
 */
#[Fillable(['category_id', 'title', 'description', 'price', 'is_negotiable', 'condition', 'province_id', 'city_id', 'area_id', 'latitude', 'longitude'])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, Searchable;

    public function shouldBeSearchable(): bool
    {
        return $this->status === ListingStatus::Published && $this->published_at !== null;
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        // A queued update may run after the ad has been withdrawn.
        if (! $this->shouldBeSearchable()) {
            return [];
        }
        $this->loadMissing(['category', 'attributeValues.attribute']);
        $attributes = [];
        $text = [];
        foreach ($this->attributeValues as $value) {
            $raw = $value->value;
            $text[] = is_array($raw) ? implode(' ', $raw) : (string) $raw;
            if ($value->attribute->is_filterable) {
                $attributes['a_'.$value->category_attribute_id] = is_string($raw) ? mb_strtolower($raw) : $raw;
            }
        }

        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description,
            'category_name' => $this->category->name,
            'category_ids' => array_values(array_filter([$this->category_id, $this->category->parent_id])),
            'province_id' => $this->province_id, 'city_id' => $this->city_id, 'area_id' => $this->area_id,
            'condition' => $this->condition->value, 'price' => (float) $this->price,
            'published_at' => $this->published_at?->timestamp,
            'expires_at' => $this->expires_at->timestamp ?? 253402300799,
            'attributes' => $attributes, 'attribute_text' => implode(' ', $text),
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Listing $listing): void {
            $listing->slug ??= Str::limit(Str::slug($listing->title) ?: 'item', 170, '').'-'.Str::lower((string) Str::ulid());
            $listing->status ??= ListingStatus::Draft;
            $listing->currency ??= 'PKR';
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return HasMany<ListingAttributeValue, $this> */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ListingAttributeValue::class);
    }

    /** @return HasMany<ListingImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @param Builder<Listing> $query
     * @return Builder<Listing>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereHas('user', fn (Builder $users) => $users->whereNull('suspended_at'))->where('status', ListingStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'moderation_flags' => 'array',
            'price' => 'decimal:2',
            'is_negotiable' => 'boolean',
            'condition' => ListingCondition::class,
            'status' => ListingStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'published_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'sold_at' => 'immutable_datetime',
        ];
    }
}
