<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\City;
use App\Models\Province;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeds_are_repeatable_and_preserve_edits(): void
    {
        $this->seed();
        $counts = [Category::count(), CategoryAttribute::count(), City::count()];
        $sofas = Category::where('slug', 'sofas')->firstOrFail();
        $sofas->update(['name' => 'Used Sofas']);
        $this->seed();
        $this->assertSame($counts, [Category::count(), CategoryAttribute::count(), City::count()]);
        $this->assertSame(15, Category::whereNull('parent_id')->count());
        $this->assertSame('Used Sofas', $sofas->fresh()->name);
        $this->assertSame('furniture', $sofas->parent->slug);
        $this->assertSame(7, Province::count());
        $this->assertSame('sindh', City::where('slug', 'karachi')->firstOrFail()->province->slug);
        $this->assertSame('islamabad-capital-territory', City::where('slug', 'islamabad')->firstOrFail()->province->slug);
        $this->assertSame(4, City::where('slug', 'karachi')->firstOrFail()->areas()->count());
        foreach (AttributeType::cases() as $type) {
            $this->assertTrue(CategoryAttribute::where('type', $type)->exists());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_categories_cannot_parent_themselves(): void
    {
        $category = Category::factory()->create();
        $this->expectException(ValidationException::class);
        $category->update(['parent_id' => $category->id]);
    }

    public function test_subcategories_cannot_have_subcategories(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $this->expectException(ValidationException::class);
        Category::factory()->create(['parent_id' => $child->id]);
    }

    public function test_category_with_children_cannot_be_moved_below_another_category(): void
    {
        $root = Category::factory()->create();
        Category::factory()->create(['parent_id' => $root->id]);
        $other = Category::factory()->create();
        $this->expectException(ValidationException::class);
        $root->update(['parent_id' => $other->id]);
    }
}
