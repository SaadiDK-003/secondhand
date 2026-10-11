<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Area;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Area> */
class AreaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => fake()->streetName(),
            'slug' => 'area-'.fake()->unique()->uuid(),
        ];
    }
}
