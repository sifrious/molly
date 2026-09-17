<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table): void {
            $table->json('journal_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table): void {
            $table->dropColumn('journal_status');
        });
    }
};
