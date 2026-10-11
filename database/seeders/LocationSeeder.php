<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        // Initial coverage, not a complete administrative directory.
        // The provinces table also holds territories for the location selector.
        $locations = [
            'Sindh' => [
                'Karachi' => ['North Nazimabad', 'Gulshan-e-Iqbal', 'DHA', 'Clifton'],
                'Hyderabad' => [],
                'Sukkur' => [],
            ],
            'Punjab' => ['Lahore' => [], 'Rawalpindi' => [], 'Faisalabad' => [], 'Multan' => []],
            'Khyber Pakhtunkhwa' => ['Peshawar' => [], 'Abbottabad' => []],
            'Balochistan' => ['Quetta' => []],
            'Islamabad Capital Territory' => ['Islamabad' => []],
            'Gilgit-Baltistan' => ['Gilgit' => []],
            'Azad Jammu and Kashmir' => ['Muzaffarabad' => []],
        ];

        foreach ($locations as $provinceName => $cities) {
            $province = Province::firstOrCreate(['slug' => Str::slug($provinceName)], ['name' => $provinceName]);
            foreach ($cities as $cityName => $areas) {
                $city = $province->cities()->firstOrCreate(['slug' => Str::slug($cityName)], ['name' => $cityName]);
                foreach ($areas as $areaName) {
                    $city->areas()->firstOrCreate(['slug' => Str::slug($areaName)], ['name' => $areaName]);
                }
            }
        }
    }
}
