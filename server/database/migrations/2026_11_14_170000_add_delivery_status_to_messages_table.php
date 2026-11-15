<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // pending, sent, partial, failed, quota, no_devices, disabled
            $table->string('push_status', 16)->nullable()->after('push_count');
            $table->unsignedInteger('push_failed')->default(0)->after('push_status');
            $table->string('push_error')->nullable()->after('push_failed');
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn(['push_status', 'push_failed', 'push_error']));
    }
};
