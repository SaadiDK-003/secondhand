<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Listing> */
class ListingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'title' => 'Pre-owned household item',
            'description' => 'A used item in good working condition.',
            'price' => '5000.00',
            'condition' => ListingCondition::Good,
            'city_id' => City::factory(),
            'province_id' => fn (array $attributes) => City::query()->whereKey($attributes['city_id'])->firstOrFail()->province_id,
            'status' => ListingStatus::Draft,
        ];
    }
}
