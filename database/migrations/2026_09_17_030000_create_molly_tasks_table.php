<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molly_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('prompt');
            $table->text('workspace');
            $table->json('paths');
            $table->text('test_path');
            $table->string('status')->default('pending');
            $table->json('source')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamps();
        });

        Schema::table('molly_runs', function (Blueprint $table) {
            $table->foreignUuid('task_id')->nullable()->constrained('molly_tasks');
        });
    }

    public function down(): void
    {
        Schema::table('molly_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });

        Schema::dropIfExists('molly_tasks');
    }
};
