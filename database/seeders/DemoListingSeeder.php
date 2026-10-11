<?php

namespace Database\Seeders;

use App\Jobs\ProcessListingImage;
use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoListingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || config('database.connections.pgsql.database') !== 'second_hand_preview') {
            throw new RuntimeException('Demo listings are restricted to the local preview database.');
        }

        config(['scout.queue' => false]);
        $this->call(DemoAccountSeeder::class);
        $seller = User::where('email', 'demo-seller@example.invalid')->firstOrFail();
        $items = [
            ['sofa', 'Tan leather sofa', 'sofas', 'Lahore', 48000, 'living-room.webp', 'A warm tan sofa for a living room or reading corner. The photograph shows the sofa in a furnished room; tables, cushions and plants are not included.'],
            ['tech', 'Camera and headphones bundle', 'cameras', 'Islamabad', 32000, 'everyday-tech.webp', 'A photography and audio bundle illustrated with a camera and over-ear headphones. Accessories visible around the products are styling props. Exact model, battery condition and included cables would be confirmed by a real seller.'],
            ['laptop', 'Silver laptop for a home workspace', 'laptops', 'Karachi', 65000, 'laptop.webp', 'A silver laptop pictured on a wooden desk. Desk, books and chair are not included. A real listing should confirm the processor, memory, storage, battery health and charger before purchase.'],
            ['backpack', 'Brown everyday backpack', 'bags-accessories', 'Rawalpindi', 4500, 'backpack.webp', 'A brown backpack with shoulder straps for everyday carrying. The chair is not included. A real seller should provide measurements and close-up photos of the lining, straps and fastenings.'],
        ];
        foreach ($items as [$key, $title, $category, $cityName, $price, $photo, $description]) {
            $city = City::where('name', $cityName)->firstOrFail();
            $slug = 'demo-'.$key;
            $listing = Listing::where('slug', $slug)->first();
            if (! $listing) {
                $listing = new Listing;
                $listing->forceFill([
                    'user_id' => $seller->id, 'slug' => $slug, 'title' => '[Demo] '.$title,
                    'description' => "DEMO LISTING — not available for purchase. This is a real stock photograph with an illustrative price and condition, not a verified seller offer.\n\n".$description,
                    'category_id' => Category::where('slug', $category)->firstOrFail()->id,
                    'city_id' => $city->id, 'province_id' => $city->province_id, 'price' => $price,
                    'condition' => 'good', 'is_negotiable' => true, 'status' => 'draft',
                ])->save();
            }
            if ($listing->user_id !== $seller->id) {
                throw new RuntimeException('Demo slug belongs to another seller.');
            }
            if (! $listing->images()->where('status', 'ready')->exists()) {
                $disk = config('listings.images_disk');
                $directory = 'demo/'.$key;
                $source = public_path('images/demo/'.$photo);
                if (! is_file($source)) {
                    throw new RuntimeException('Missing demo photograph: '.$photo);
                }
                $bytes = file_get_contents($source);
                if ($bytes === false || ! Storage::disk($disk)->put($directory.'/original.webp', $bytes)) {
                    throw new RuntimeException('Could not store demo photograph.');
                }
                $image = $listing->images()->create(['disk' => $disk, 'directory' => $directory, 'original_path' => $directory.'/original.webp', 'sort_order' => 0]);
                app()->call([new ProcessListingImage($image->id), 'handle']);
            }
            $listing->forceFill(['status' => 'published', 'published_at' => now(), 'expires_at' => now()->addDays(30)])->save();
        }
    }
}
