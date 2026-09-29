<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('molly_tasks')) {
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
        }

        $this->recoverRunsCopy();
        if (! Schema::hasColumn('molly_runs', 'task_id')) {
            Schema::table('molly_runs', function (Blueprint $table) {
                $table->foreignUuid('task_id')->nullable()->constrained('molly_tasks');
            });
        } elseif (! collect(Schema::getForeignKeys('molly_runs'))->contains(fn (array $key): bool => $key['columns'] === ['task_id'])) {
            // An interrupted migrate added the column but not its foreign key.
            Schema::table('molly_runs', function (Blueprint $table) {
                $table->foreign('task_id')->references('id')->on('molly_tasks');
            });
        }
    }

    /**
     * On SQLite, Laravel adds the foreign key by copying molly_runs into __temp__molly_runs,
     * dropping molly_runs, and renaming the copy, with no transaction around the steps. A copy
     * left by an interrupted migrate is discarded while molly_runs still exists, and renamed
     * into place when molly_runs was already dropped, because the copy then holds every row.
     */
    private function recoverRunsCopy(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }
        $runs = $connection->getTablePrefix().'molly_runs';
        $copy = '__temp__'.$runs;
        $tables = array_column(Schema::getTables(), 'name');
        if (! in_array($copy, $tables, true)) {
            return;
        }

        $connection->statement(in_array($runs, $tables, true)
            ? 'drop table "'.$copy.'"'
            : 'alter table "'.$copy.'" rename to "'.$runs.'"');
    }

    public function down(): void
    {
        Schema::table('molly_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });

        Schema::dropIfExists('molly_tasks');
    }
};
