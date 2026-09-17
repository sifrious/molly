<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molly_task_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('task_id')->constrained('molly_tasks')->cascadeOnDelete();
            $table->string('thread_id', 38)->index();
            $table->timestamp('linked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molly_task_threads');
    }
};
