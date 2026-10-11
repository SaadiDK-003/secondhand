<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['reports', 'activity_logs'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->ipAddress('ip_address')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['reports', 'activity_logs'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('ip_address'));
        }
    }
};
