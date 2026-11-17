<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relay_client_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['token_hash', 'relay_client_id']);
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_tokens');
    }
};
