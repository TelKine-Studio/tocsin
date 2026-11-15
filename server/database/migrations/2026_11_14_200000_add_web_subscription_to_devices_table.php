<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Browser push subscription (endpoint + keys). "token" then holds
            // a hash of the endpoint, which can be longer than the column.
            $table->text('web_subscription')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('web_subscription'));
    }
};
