<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
            $table->index(['user_id', 'branch_group_id', 'read_at'], 'notifications_recipient_group_read');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve notification group snapshots; use a forward migration.');
    }
};
