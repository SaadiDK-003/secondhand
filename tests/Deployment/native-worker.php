<?php

declare(strict_types=1);

// Disposable VM integration fixture; deploy.sh never runs this file.
use App\Enums\ListingCondition;
use App\Jobs\ProcessListingImage;
use App\Models\Category;
use App\Models\City;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli' || getenv('SECONDHAND_DISPOSABLE_VM') !== 'yes') {
    exit(1);
}
$root = '/var/www/secondhand/app';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('queue.default') !== 'redis' || config('listings.images_disk') !== 's3') {
    throw new RuntimeException('Expected the native Redis/private storage stack');
}
$user = null;
$paths = [];
$disk = Storage::disk('s3');
try {
    $user = User::create(['name' => 'Disposable worker fixture', 'email' => Str::uuid().'@example.invalid',
        'mobile_number' => '+923001234567', 'password' => Str::random(48)]);
    $city = City::firstOrFail();
    $listing = $user->listings()->create([
        'category_id' => Category::whereNotNull('parent_id')->firstOrFail()->id,
        'title' => 'Private worker fixture', 'description' => 'Disposable unpublished fixture',
        'price' => 1, 'condition' => ListingCondition::Good, 'province_id' => $city->province_id, 'city_id' => $city->id,
    ]);
    foreach (['png', 'jpeg'] as $format) {
        $directory = 'deployment-checks/'.Str::uuid();
        $source = $directory.'/original';
        $paths[] = $source;
        foreach (array_keys(config('listings.variants')) as $variant) {
            $paths[] = $directory.'/'.$variant.'.webp';
        }
        $canvas = imagecreatetruecolor(64, 48);
        ob_start();
        $format === 'png' ? imagepng($canvas) : imagejpeg($canvas);
        $bytes = ob_get_clean();
        if (! $disk->put($source, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('Fixture upload failed');
        }
        $image = $listing->images()->create(['disk' => 's3', 'directory' => $directory, 'original_path' => $source]);
        ProcessListingImage::dispatch($image->id);
        for ($attempt = 0; $attempt < 60 && ($image->fresh()->status !== 'ready' || $image->fresh()->original_path !== null); $attempt++) {
            sleep(1);
        }
        if ($image->fresh()->status !== 'ready' || $image->fresh()->original_path !== null) {
            throw new RuntimeException('Horizon did not finish the queued image');
        }
        foreach (array_keys(config('listings.variants')) as $variant) {
            $info = getimagesizefromstring($disk->get($directory.'/'.$variant.'.webp'));
            if (! $info || $info['mime'] !== 'image/webp') {
                throw new RuntimeException('Queued variant is not WebP');
            }
        }
    }
    echo "Native Horizon processed JPEG/PNG into private WebP variants.\n";
} finally {
    foreach ($paths as $path) {
        $disk->delete($path);
    }
    $user?->delete();
}
