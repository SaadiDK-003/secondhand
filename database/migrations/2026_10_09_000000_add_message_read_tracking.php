<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('read_at')->nullable();
        });
        DB::statement('CREATE INDEX messages_unread_conversation_index ON messages (conversation_id, id) WHERE read_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX messages_unread_conversation_index');
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('read_at');
        });
    }
};
