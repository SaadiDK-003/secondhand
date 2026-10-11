<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Mobiles & Tablets' => ['Mobile Phones', 'Tablets', 'Mobile Accessories'],
            'Computers & Laptops' => ['Laptops', 'Desktop Computers', 'Computer Accessories'],
            'Electronics' => ['Televisions', 'Audio Equipment', 'Cameras'],
            'Home Appliances' => ['Refrigerators', 'Washing Machines', 'Kitchen Appliances', 'Air Conditioners'],
            'Furniture' => ['Sofas', 'Beds & Mattresses', 'Tables & Chairs', 'Storage Furniture'],
            'Fashion' => ['Clothing', 'Shoes', 'Bags & Accessories'],
            'Wedding Clothes & Accessories' => ['Bridal Dresses', 'Sherwanis', 'Wedding Accessories'],
            'Kids & Baby Items' => ['Baby Gear', 'Toys', 'Kids Clothing'],
            'Gaming' => ['Consoles', 'Video Games', 'Gaming Accessories'],
            'Bikes & Cycles' => ['Bicycles', 'Bike Accessories'],
            'Sports & Fitness' => ['Fitness Equipment', 'Sports Equipment'],
            'Books' => ['Textbooks', 'Fiction & Nonfiction'],
            'Tools & Equipment' => ['Hand Tools', 'Power Tools'],
            'Hobbies & Collectibles' => ['Collectibles', 'Musical Instruments', 'Arts & Crafts'],
            'Other Used Items' => [],
        ];

        foreach (array_keys($categories) as $order => $name) {
            $parent = Category::firstOrCreate(['slug' => Str::slug($name)], [
                'name' => $name, 'sort_order' => $order,
            ]);
            foreach ($categories[$name] as $childOrder => $childName) {
                Category::firstOrCreate(['slug' => Str::slug($childName)], [
                    'name' => $childName, 'parent_id' => $parent->id, 'sort_order' => $childOrder,
                ]);
            }
        }
    }
}
