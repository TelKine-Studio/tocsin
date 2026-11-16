<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SHA-256 of the X-Ack-Group key: messages of one event published
        // in several topics, acknowledged together.
        Schema::table('messages', function (Blueprint $table) {
            $table->string('ack_group', 64)->nullable()->after('requires_ack');
            $table->index('ack_group');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['ack_group']);
            $table->dropColumn('ack_group');
        });
    }
};
