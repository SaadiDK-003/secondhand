<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Only new registrations get this deadline. Never prune legacy accounts.
            $table->timestamp('registration_expires_at')->nullable()->index();
            $table->string('email_activation_code_hash')->nullable();
            $table->timestamp('email_activation_expires_at')->nullable();
            $table->timestamp('email_activation_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['registration_expires_at']);
            $table->dropColumn(['registration_expires_at', 'email_activation_code_hash', 'email_activation_expires_at', 'email_activation_sent_at']);
        });
    }
};
