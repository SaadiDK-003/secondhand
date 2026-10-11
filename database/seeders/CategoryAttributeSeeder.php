<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategoryAttributeSeeder extends Seeder
{
    public function run(): void
    {
        // name, type, required, filterable, options. Condition is shared by all listings.
        $definitions = [
            'mobile-phones' => [
                ['Brand', AttributeType::Select, true, true, ['Apple', 'Samsung', 'Xiaomi', 'Oppo', 'Vivo', 'Other']],
                ['Model', AttributeType::Text, true, false, []],
                ['Storage', AttributeType::Select, true, true, ['32 GB', '64 GB', '128 GB', '256 GB', '512 GB', '1 TB', 'Other']],
                ['RAM', AttributeType::Number, false, true, []],
                ['PTA Approved', AttributeType::Boolean, true, true, []],
                ['Warranty', AttributeType::Boolean, false, true, []],
                ['Box Available', AttributeType::Boolean, false, false, []],
            ],
            'refrigerators' => [
                ['Brand', AttributeType::Text, true, true, []],
                ['Capacity', AttributeType::Number, false, true, []],
                ['Type', AttributeType::Select, true, true, ['Single Door', 'Double Door', 'Side by Side', 'Other']],
                ['Inverter', AttributeType::Boolean, false, true, []],
                ['Warranty', AttributeType::Boolean, false, false, []],
            ],
            'sofas' => [
                ['Seats', AttributeType::Number, true, true, []],
                ['Material', AttributeType::Select, false, true, ['Fabric', 'Leather', 'Wood', 'Other']],
                ['Color', AttributeType::Text, false, false, []],
                ['Dimensions', AttributeType::Text, false, false, []],
            ],
            'bridal-dresses' => $this->weddingAttributes(),
            'sherwanis' => $this->weddingAttributes(),
            'laptops' => [
                ['Brand', AttributeType::Text, true, true, []],
                ['Model', AttributeType::Text, true, false, []],
                ['RAM', AttributeType::Number, false, true, []],
                ['Included Accessories', AttributeType::MultiSelect, false, false, ['Charger', 'Bag', 'Mouse', 'Original Box']],
            ],
        ];

        foreach ($definitions as $categorySlug => $attributes) {
            $category = Category::where('slug', $categorySlug)->firstOrFail();
            foreach ($attributes as $order => [$name, $type, $required, $filterable, $options]) {
                $attribute = $category->attributes()->firstOrCreate(['slug' => Str::slug($name)], [
                    'name' => $name, 'type' => $type, 'is_required' => $required,
                    'is_filterable' => $filterable, 'sort_order' => $order,
                ]);
                foreach ($options as $optionOrder => $label) {
                    $attribute->options()->firstOrCreate(['value' => Str::slug($label)], [
                        'label' => $label, 'sort_order' => $optionOrder,
                    ]);
                }
            }
        }
    }

    /** @return list<array{string, AttributeType, bool, bool, list<string>}> */
    private function weddingAttributes(): array
    {
        return [
            ['Gender', AttributeType::Select, false, true, ['Women', 'Men', 'Unisex']],
            ['Dress Type', AttributeType::Text, true, true, []],
            ['Size', AttributeType::Text, true, true, []],
            ['Designer', AttributeType::Text, false, true, []],
            ['Color', AttributeType::Text, false, false, []],
            ['Used Count', AttributeType::Number, false, false, []],
        ];
    }
}
