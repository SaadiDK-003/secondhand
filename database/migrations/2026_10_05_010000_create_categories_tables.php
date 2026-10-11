<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        Schema::create('category_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->enum('type', array_column(AttributeType::cases(), 'value'));
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'slug']);
            $table->unique(['id', 'category_id']);
        });

        Schema::create('category_attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('value', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category_attribute_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_attribute_options');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('categories');
    }
};
