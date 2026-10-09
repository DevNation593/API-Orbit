<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->after('occurred_at');
            $table->index(['tenant_id', 'status', 'scheduled_at'], 'messages_scheduled_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_scheduled_delivery_index');
            $table->dropColumn('scheduled_at');
        });
    }
};
