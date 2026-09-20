<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('molly_runs', function (Blueprint $table) {
            $table->json('effective_config')->nullable()->after('report');
        });
    }

    public function down(): void
    {
        Schema::table('molly_runs', function (Blueprint $table) {
            $table->dropColumn('effective_config');
        });
    }
};
