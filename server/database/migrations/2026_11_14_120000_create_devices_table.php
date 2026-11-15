<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16)->default('ios');
            $table->string('environment', 16)->default('production');
            $table->string('token', 200)->unique();
            $table->string('name')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_topic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['device_id', 'topic_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedInteger('push_count')->default(0)->after('click_url');
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('push_count'));
        Schema::dropIfExists('device_topic');
        Schema::dropIfExists('devices');
    }
};
