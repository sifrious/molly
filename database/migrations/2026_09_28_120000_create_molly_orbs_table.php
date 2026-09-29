<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molly_orbs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('provider');
            $table->string('device');
            $table->string('runtime');
            $table->string('model')->nullable();
            $table->text('repository_path');
            $table->text('repository_git_dir');
            $table->string('repository_remote_identity')->nullable();
            $table->text('worktree_root');
            $table->string('health')->default('unknown');
            $table->text('health_reason')->nullable();
            $table->json('runtime_identity')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->uuid('current_task_id')->nullable()->index();
            $table->timestamp('reserved_at')->nullable();
            $table->string('reserved_prompt_sha256', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molly_orbs');
    }
};
