<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each send merged into a grouped message (time and content).
        Schema::table('messages', fn (Blueprint $table) => $table->json('repeats')->nullable()->after('occurrences'));
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('repeats'));
    }
};
