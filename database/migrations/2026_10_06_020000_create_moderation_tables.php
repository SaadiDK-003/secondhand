<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->boolean('is_admin')->default(false);
            $t->timestamp('suspended_at')->nullable();
            $t->text('suspension_reason')->nullable();
        });
        Schema::table('listings', function (Blueprint $t): void {
            $t->jsonb('moderation_flags')->nullable();
            $t->text('moderation_note')->nullable();
        });
        Schema::create('reports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('target_type', 20);
            $t->unsignedBigInteger('target_id');
            $t->string('reason', 40);
            $t->text('details')->nullable();
            $t->string('status', 20)->default('open');
            $t->text('resolution')->nullable();
            $t->timestamps();
            $t->unique(['reporter_id', 'target_type', 'target_id']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('prohibited_keywords', function (Blueprint $t): void {
            $t->id();
            $t->string('phrase', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->string('target_type', 40);
            $t->unsignedBigInteger('target_id');
            $t->jsonb('details');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('prohibited_keywords');
        Schema::dropIfExists('reports');
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['moderation_flags', 'moderation_note']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'suspended_at', 'suspension_reason']));
    }
};
