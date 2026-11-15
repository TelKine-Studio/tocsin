<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('actions')->nullable()->after('click_url');
            $table->string('icon_url', 2048)->nullable()->after('actions');
            $table->string('attachment_url', 2048)->nullable()->after('icon_url');
            $table->string('attachment_name')->nullable()->after('attachment_url');
            $table->string('attachment_type', 100)->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_type');
            $table->string('attachment_path')->nullable()->after('attachment_size');
            // Scheduled messages (X-Delay) are stored right away and delivered later.
            $table->timestamp('scheduled_at')->nullable()->after('created_at');
            $table->timestamp('dispatched_at')->nullable()->index()->after('scheduled_at');
        });

        DB::table('messages')->update(['dispatched_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['actions', 'icon_url', 'attachment_url', 'attachment_name', 'attachment_type', 'attachment_size', 'attachment_path', 'scheduled_at', 'dispatched_at']);
        });
    }
};
