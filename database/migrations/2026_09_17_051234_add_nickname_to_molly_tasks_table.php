<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The column and its unique index are separate statements, and an interrupted migrate can
        // leave the column without the index. Running it again adds only what is missing.
        if (! Schema::hasColumn('molly_tasks', 'nickname')) {
            Schema::table('molly_tasks', function (Blueprint $table) {
                $table->string('nickname', 64)->nullable()->unique();
            });
        }
        if (! Schema::hasIndex('molly_tasks', ['nickname'], 'unique')) {
            Schema::table('molly_tasks', function (Blueprint $table) {
                $table->unique('nickname');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->dropUnique(['nickname']);
            $table->dropColumn('nickname');
        });
    }
};
