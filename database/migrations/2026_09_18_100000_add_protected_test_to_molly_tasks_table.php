<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->string('test_digest', 64)->nullable();
            $table->boolean('allow_test_edits')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->dropColumn(['test_digest', 'allow_test_edits']);
        });
    }
};
