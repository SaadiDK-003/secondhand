<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('listing_title');
            $table->timestamps();
            $table->unique(['listing_id', 'buyer_id']);
            $table->index(['buyer_id', 'updated_at']);
            $table->index(['seller_id', 'updated_at']);
        });
        DB::statement('ALTER TABLE conversations ADD CONSTRAINT different_participants CHECK (buyer_id <> seller_id)');
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });
        Schema::create('blocked_users', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'blocked_user_id']);
            $table->index('blocked_user_id');
        });
        DB::statement('ALTER TABLE blocked_users ADD CONSTRAINT no_self_block CHECK (user_id <> blocked_user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_users');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
