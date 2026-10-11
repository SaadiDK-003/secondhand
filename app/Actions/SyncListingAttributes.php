<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AttributeType;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SyncListingAttributes
{
    /** Replace a listing's attributes atomically. HTTP callers must authorize the listing first.
     * @param  array<string, mixed>  $values  Attribute slugs mapped to their values.
     */
    public function handle(Listing $listing, array $values): void
    {
        DB::transaction(function () use ($listing, $values): void {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $attributes = $locked->category->attributes()->with('options')->get();

            if (array_diff(array_keys($values), $attributes->pluck('slug')->all()) !== []) {
                throw ValidationException::withMessages(['attributes' => 'An attribute does not belong to this category.']);
            }

            $rules = [];
            foreach ($attributes as $attribute) {
                $key = 'attributes.'.$attribute->slug;
                $typeRules = match ($attribute->type) {
                    AttributeType::Text => ['string', 'not_regex:/\x00/', 'max:1000'],
                    AttributeType::Number => ['numeric', 'between:-1000000000000,1000000000000'],
                    AttributeType::Boolean => ['boolean'],
                    AttributeType::Select => ['string', Rule::in($attribute->options->pluck('value')->all())],
                    AttributeType::MultiSelect => ['array', 'max:30'],
                };
                $rules[$key] = [$attribute->is_required ? 'required' : 'nullable', ...$typeRules];
                if ($attribute->type === AttributeType::MultiSelect) {
                    $rules[$key.'.*'] = ['required', 'string', 'distinct', Rule::in($attribute->options->pluck('value')->all())];
                }
            }
            Validator::make(['attributes' => $values], $rules)->validate();

            $locked->attributeValues()->delete();
            foreach ($attributes as $attribute) {
                $value = $values[$attribute->slug] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                $value = match ($attribute->type) {
                    AttributeType::Boolean => (bool) $value,
                    AttributeType::Number => is_numeric($value) ? $value + 0 : $value,
                    default => $value,
                };
                $locked->attributeValues()->create([
                    'category_attribute_id' => $attribute->id,
                    'category_id' => $locked->category_id,
                    'value' => $value,
                ]);
            }
            $locked->touch();
        });
        $listing->unsetRelation('attributeValues');
    }
}
