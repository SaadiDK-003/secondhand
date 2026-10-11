<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListingCondition;
use App\Models\Category;
use App\Models\Listing;
use App\Rules\SafeListingImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SaveListingRequest extends FormRequest
{
    /** @return list<UploadedFile> */
    public function photos(): array
    {
        $files = $this->file('images', []);

        return is_array($files) ? array_values($files) : [];
    }

    public function authorize(): bool
    {
        $listing = $this->route('listing');

        return $listing instanceof Listing
            ? $this->user()?->can('update', $listing) === true
            : $this->user()?->can('create', Listing::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $listing = $this->route('listing');

        return [
            'category_id' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                $category = Category::with('parent')->whereKey($value)->first();
                if (! $category || ! $category->is_active || ($category->parent && ! $category->parent->is_active) || $category->children()->exists()) {
                    $fail('Choose an available subcategory.');
                }
            }],
            'title' => ['bail', 'required', 'string', 'not_regex:/\x00/', 'min:5', 'max:160'],
            'description' => ['bail', 'required', 'string', 'not_regex:/\x00/', 'min:20', 'max:10000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'is_negotiable' => ['required', 'boolean'],
            'condition' => ['required', Rule::enum(ListingCondition::class)],
            'province_id' => ['bail', 'required', 'integer', 'exists:provinces,id'],
            'city_id' => ['bail', 'required', 'integer', Rule::exists('cities', 'id')->where('province_id', filter_var($this->input('province_id'), FILTER_VALIDATE_INT) ?: 0)],
            'area_id' => ['bail', 'nullable', 'integer', Rule::exists('areas', 'id')->where('city_id', filter_var($this->input('city_id'), FILTER_VALIDATE_INT) ?: 0)],
            'attributes' => ['sometimes', 'array', 'max:30'],
            'images' => ['sometimes', 'array', 'max:12'],
            'images.*' => ['bail', 'file', 'max:10240', new SafeListingImage],
            'remove_image_ids' => ['sometimes', 'array', 'max:12'],
            'remove_image_ids.*' => ['bail', 'integer', 'distinct', Rule::exists('listing_images', 'id')->where('listing_id', $listing instanceof Listing ? $listing->id : 0)],
        ];
    }
}
