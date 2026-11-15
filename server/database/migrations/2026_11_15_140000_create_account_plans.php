<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosted mode (TOCSIN_HOSTED): accounts get a plan with a daily message
     * quota, paid like relay plans.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan', 32)->default('free')->after('role');
            // Custom limit set by an administrator; null = the plan's limit.
            $table->unsignedInteger('daily_limit')->nullable()->after('plan');
            $table->timestamp('plan_expires_at')->nullable()->after('daily_limit');
        });

        Schema::create('account_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('date', 10);
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->boolean('warned')->default(false);
            $table->unique(['user_id', 'date']);
        });

        // Payments now belong to a relay instance or to an account.
        Schema::table('relay_payments', function (Blueprint $table) {
            $table->foreignId('relay_client_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->after('relay_client_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('relay_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::dropIfExists('account_usages');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['plan', 'daily_limit', 'plan_expires_at']));
    }
};
