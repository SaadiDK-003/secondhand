<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Actions\SyncListingAttributes;
use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\Listing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ListingAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_attribute_types_preserve_native_values_including_false_and_zero(): void
    {
        $listing = $this->listingWithAttributes();
        app(SyncListingAttributes::class)->handle($listing, $this->values());
        $actual = $listing->attributeValues()->with('attribute')->get()->mapWithKeys(
            fn ($value) => [$value->attribute->slug => $value->value],
        )->all();
        $this->assertEquals($this->values(), $actual);
        $this->assertFalse($actual['boolean']);
        $this->assertSame(0, $actual['number']);
        $this->assertSame(['one', 'two'], $actual['multi_select']);
    }

    public function test_invalid_option_does_not_replace_existing_values(): void
    {
        $listing = $this->listingWithAttributes();
        app(SyncListingAttributes::class)->handle($listing, $this->values());
        try {
            app(SyncListingAttributes::class)->handle($listing, $this->values(['select' => 'not-an-option']));
            $this->fail('Invalid option was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attributes.select', $exception->errors());
        }
        $this->assertSame(5, $listing->attributeValues()->count());
        $this->assertSame('one', $listing->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'select'))->firstOrFail()->value);
    }

    public function test_required_values_and_wrong_types_are_rejected(): void
    {
        $listing = $this->listingWithAttributes();
        try {
            app(SyncListingAttributes::class)->handle($listing, $this->values([
                'text' => ['wrong'], 'number' => 'not-a-number', 'boolean' => null,
                'multi_select' => ['one', 'one'],
            ]));
            $this->fail('Invalid types were accepted.');
        } catch (ValidationException $exception) {
            foreach (['text', 'number', 'boolean', 'multi_select.0'] as $field) {
                $this->assertArrayHasKey('attributes.'.$field, $exception->errors());
            }
        }
        $this->assertSame(0, $listing->attributeValues()->count());
    }

    public function test_unknown_category_attributes_are_rejected(): void
    {
        $listing = $this->listingWithAttributes();
        $this->expectException(ValidationException::class);
        app(SyncListingAttributes::class)->handle($listing, $this->values(['unknown' => 'value']));
    }

    public function test_optional_attributes_can_be_cleared(): void
    {
        $listing = $this->listingWithAttributes();
        $listing->category->attributes()->update(['is_required' => false]);
        app(SyncListingAttributes::class)->handle($listing, $this->values());
        app(SyncListingAttributes::class)->handle($listing, []);
        $this->assertSame(0, $listing->attributeValues()->count());
    }

    public function test_database_rejects_an_attribute_from_another_category(): void
    {
        $listing = $this->listingWithAttributes();
        $attribute = Category::factory()->create()->attributes()->create([
            'name' => 'Other', 'slug' => 'other', 'type' => AttributeType::Text,
        ]);
        $this->expectException(QueryException::class);
        $listing->attributeValues()->create([
            'category_id' => $listing->category_id,
            'category_attribute_id' => $attribute->id,
            'value' => 'invalid',
        ]);
    }

    public function test_deleting_an_account_removes_its_listings_and_attribute_values(): void
    {
        $listing = $this->listingWithAttributes();
        app(SyncListingAttributes::class)->handle($listing, $this->values());
        $listing->user->delete();
        $this->assertDatabaseCount('listings', 0);
        $this->assertDatabaseCount('listing_attribute_values', 0);
        $this->assertDatabaseCount('categories', 1);
    }

    private function listingWithAttributes(): Listing
    {
        $listing = Listing::factory()->create();
        foreach (AttributeType::cases() as $type) {
            $attribute = $listing->category->attributes()->create([
                'name' => $type->value, 'slug' => $type->value, 'type' => $type,
                'is_required' => true,
            ]);
            if (in_array($type, [AttributeType::Select, AttributeType::MultiSelect], true)) {
                foreach (['one', 'two'] as $value) {
                    $attribute->options()->create(['label' => ucfirst($value), 'value' => $value]);
                }
            }
        }

        return $listing;
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function values(array $overrides = []): array
    {
        return array_replace([
            'text' => 'Used item', 'number' => 0, 'boolean' => false,
            'select' => 'one', 'multi_select' => ['one', 'two'],
        ], $overrides);
    }
}
