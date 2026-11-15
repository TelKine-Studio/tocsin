<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instances allowed to send notifications through this relay.
        Schema::create('relay_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('email');
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 16);
            $table->string('plan', 32)->default('free');
            $table->unsignedInteger('daily_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('registered_ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('relay_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relay_client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('rejected')->default(0);

            $table->unique(['relay_client_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_usages');
        Schema::dropIfExists('relay_clients');
    }
};
