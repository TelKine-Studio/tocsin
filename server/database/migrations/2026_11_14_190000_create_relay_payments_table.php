<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relay_clients', function (Blueprint $table) {
            $table->timestamp('plan_expires_at')->nullable()->after('daily_limit');
        });

        Schema::create('relay_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relay_client_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('plan', 32);
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('provider', 16);
            $table->string('provider_reference')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('period_days')->default(30);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_payments');
        Schema::table('relay_clients', fn (Blueprint $table) => $table->dropColumn('plan_expires_at'));
    }
};
