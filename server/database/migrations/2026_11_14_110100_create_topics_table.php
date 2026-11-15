<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('visibility', 16)->default('private')->index();
            $table->boolean('anonymous_publish')->default(false);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('topic_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_read')->default(true);
            $table->boolean('can_write')->default(false);
            $table->timestamps();

            $table->unique(['topic_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_user');
        Schema::dropIfExists('topics');
    }
};
