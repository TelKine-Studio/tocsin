<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Grouping: repeated alerts are counted on a single message.
            $table->string('collapse_key', 191)->nullable()->after('push_error');
            $table->unsignedInteger('occurrences')->default(1)->after('collapse_key');
            $table->timestamp('last_seen_at')->nullable()->after('occurrences');

            // Acknowledgement and escalation.
            $table->boolean('requires_ack')->default(false)->after('last_seen_at');
            $table->timestamp('acknowledged_at')->nullable()->after('requires_ack');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->string('acknowledged_name', 100)->nullable()->after('acknowledged_by');
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('acknowledged_name');
            $table->timestamp('next_escalation_at')->nullable()->after('escalation_level')->index();

            $table->index(['topic_id', 'collapse_key', 'last_seen_at']);
        });

        Schema::table('topics', function (Blueprint $table) {
            // Identical messages (or messages with the same X-Collapse key)
            // received within this many minutes are grouped. 0 disables it.
            $table->unsignedSmallInteger('group_window')->default(10)->after('anonymous_publish');
            // Messages with at least this priority must be acknowledged.
            $table->unsignedTinyInteger('ack_priority')->nullable()->after('group_window');
            // Reminder steps: [{"after": minutes, "users": [user ids]}].
            $table->json('escalation')->nullable()->after('ack_priority');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['topic_id', 'collapse_key', 'last_seen_at']);
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['collapse_key', 'occurrences', 'last_seen_at', 'requires_ack', 'acknowledged_at', 'acknowledged_name', 'escalation_level', 'next_escalation_at']);
        });

        Schema::table('topics', fn (Blueprint $table) => $table->dropColumn(['group_window', 'ack_priority', 'escalation']));
    }
};
