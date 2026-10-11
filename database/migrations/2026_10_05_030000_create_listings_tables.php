<?php

declare(strict_types=1);

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->string('slug', 200)->unique();
            $table->text('description');
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('PKR');
            $table->boolean('is_negotiable')->default(false);
            $table->enum('condition', array_column(ListingCondition::cases(), 'value'));
            $table->foreignId('province_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id');
            $table->foreignId('area_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('status', array_column(ListingStatus::cases(), 'value'))->default(ListingStatus::Draft->value);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('sold_at')->nullable();
            $table->timestampsTz();
            // Composite keys reject a city/area from another location hierarchy.
            $table->foreign(['city_id', 'province_id'])->references(['id', 'province_id'])->on('cities')->restrictOnDelete();
            $table->foreign(['area_id', 'city_id'])->references(['id', 'city_id'])->on('areas')->restrictOnDelete();
            $table->unique(['id', 'category_id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'published_at']);
            $table->index(['category_id', 'status']);
            $table->index(['city_id', 'status']);
            $table->index('area_id');
            $table->index('price');
            $table->index(['status', 'expires_at']);
        });
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_price_nonnegative CHECK (price >= 0)');
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_currency_pkr CHECK (currency = 'PKR')");
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_coordinates_valid CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');

        Schema::create('listing_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id');
            $table->foreignId('category_attribute_id');
            $table->foreignId('category_id');
            $table->jsonb('value');
            $table->timestamps();
            $table->unique(['listing_id', 'category_attribute_id']);
            // An attribute and its listing must belong to the same category.
            $table->foreign(['listing_id', 'category_id'])->references(['id', 'category_id'])->on('listings')->cascadeOnDelete();
            $table->foreign(['category_attribute_id', 'category_id'])->references(['id', 'category_id'])->on('category_attributes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_attribute_values');
        Schema::dropIfExists('listings');
    }
};
