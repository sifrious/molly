<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('molly_task_threads')) {
            Schema::create('molly_task_threads', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('task_id')->constrained('molly_tasks')->cascadeOnDelete();
                $table->string('thread_id', 38)->index();
                $table->timestamp('linked_at');
            });
        }

        // Some databases add the foreign key and the index in statements after create table, so
        // an interrupted migrate can leave the table without them.
        if (! collect(Schema::getForeignKeys('molly_task_threads'))->contains(fn (array $key): bool => $key['columns'] === ['task_id'])) {
            Schema::table('molly_task_threads', function (Blueprint $table) {
                $table->foreign('task_id')->references('id')->on('molly_tasks')->cascadeOnDelete();
            });
        }
        if (! Schema::hasIndex('molly_task_threads', ['thread_id'])) {
            Schema::table('molly_task_threads', function (Blueprint $table) {
                $table->index('thread_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('molly_task_threads');
    }
};
