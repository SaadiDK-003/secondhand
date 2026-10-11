<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Province> */
class ProvinceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->word().' Province',
            'slug' => 'province-'.fake()->unique()->uuid(),
        ];
    }
}
