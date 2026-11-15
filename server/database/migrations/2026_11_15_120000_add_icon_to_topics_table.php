<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            // Logo of the topic, shown in the apps and in notifications.
            $table->string('icon_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('topics', fn (Blueprint $table) => $table->dropColumn('icon_path'));
    }
};
