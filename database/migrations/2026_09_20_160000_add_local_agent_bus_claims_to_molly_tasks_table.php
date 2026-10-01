<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each column and index is its own statement, so an interrupted migrate can leave some of
        // them behind. Running it again adds only what is missing.
        $columns = [
            'worker_id' => fn (Blueprint $table) => $table->string('worker_id')->nullable()->after('status'),
            'claimed_at' => fn (Blueprint $table) => $table->timestamp('claimed_at')->nullable()->after('worker_id'),
            'lease_expires_at' => fn (Blueprint $table) => $table->timestamp('lease_expires_at')->nullable()->after('claimed_at'),
            'heartbeat_at' => fn (Blueprint $table) => $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at'),
            'attempt_number' => fn (Blueprint $table) => $table->unsignedInteger('attempt_number')->default(0)->after('heartbeat_at'),
            'idempotency_key' => fn (Blueprint $table) => $table->string('idempotency_key')->nullable()->after('attempt_number'),
            'parent_run_id' => fn (Blueprint $table) => $table->uuid('parent_run_id')->nullable()->after('idempotency_key'),
        ];
        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('molly_tasks', $column)) {
                Schema::table('molly_tasks', $add);
            }
        }
        if (! Schema::hasIndex('molly_tasks', ['status', 'lease_expires_at'])) {
            Schema::table('molly_tasks', function (Blueprint $table) {
                $table->index(['status', 'lease_expires_at']);
            });
        }
        if (! Schema::hasIndex('molly_tasks', ['idempotency_key'], 'unique')) {
            Schema::table('molly_tasks', function (Blueprint $table) {
                $table->unique('idempotency_key');
            });
        }
    }

    public function down(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn([
                'worker_id',
                'claimed_at',
                'lease_expires_at',
                'heartbeat_at',
                'attempt_number',
                'idempotency_key',
                'parent_run_id',
            ]);
        });
    }
};
