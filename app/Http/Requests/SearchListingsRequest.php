<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AttributeType;
use App\Enums\ListingCondition;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'q' => ['bail', 'nullable', 'string', 'not_regex:/\x00/', 'max:120'],
            'category_id' => ['bail', 'nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'province_id' => ['bail', 'nullable', 'integer', 'exists:provinces,id'],
            'city_id' => ['bail', 'nullable', 'integer', Rule::exists('cities', 'id')->when($this->filled('province_id'), fn ($rule) => $rule->where('province_id', $this->integer('province_id')))],
            'area_id' => ['bail', 'nullable', 'integer', Rule::exists('areas', 'id')->where('city_id', $this->integer('city_id'))],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'condition' => ['nullable', Rule::enum(ListingCondition::class)],
            'sort' => ['nullable', Rule::in(['relevant', 'newest', 'price_asc', 'price_desc'])],
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:50'],
            'attributes' => ['nullable', 'array', 'max:30'],
        ];
        if ($this->filled('min_price')) {
            $rules['max_price'][] = 'gte:min_price';
        }
        $categoryId = filter_var($this->input('category_id'), FILTER_VALIDATE_INT);
        $category = $categoryId !== false ? Category::whereKey($categoryId)->first() : null;
        $attributes = $category?->attributes()->where('is_filterable', true)->with('options')->get() ?? collect();
        $rules['attributes'][] = function ($key, $value, $fail) use ($attributes): void {
            if (is_array($value) && array_diff(array_keys($value), $attributes->pluck('slug')->all())) {
                $fail('Choose filters belonging to the selected category.');
            }
        };
        foreach ($attributes as $attribute) {
            $key = 'attributes.'.$attribute->slug;
            $rules[$key] = ['nullable', ...match ($attribute->type) {
                AttributeType::Boolean => ['boolean'],
                AttributeType::Number => ['numeric', 'between:-1000000000000,1000000000000'],
                AttributeType::Select => ['string', Rule::in($attribute->options->pluck('value')->all())],
                AttributeType::MultiSelect => ['array', 'max:30'],
                default => ['string', 'not_regex:/\x00/', 'max:100'],
            }];
            if ($attribute->type === AttributeType::MultiSelect) {
                $rules[$key.'.*'] = ['string', 'distinct', Rule::in($attribute->options->pluck('value')->all())];
            }
        }

        return $rules;
    }
}
