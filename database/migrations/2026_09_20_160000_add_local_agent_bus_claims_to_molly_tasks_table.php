<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->string('worker_id')->nullable()->after('status');
            $table->timestamp('claimed_at')->nullable()->after('worker_id');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
            $table->unsignedInteger('attempt_number')->default(0)->after('heartbeat_at');
            $table->string('idempotency_key')->nullable()->after('attempt_number');
            $table->uuid('parent_run_id')->nullable()->after('idempotency_key');
            $table->index(['status', 'lease_expires_at']);
            $table->unique('idempotency_key');
        });
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
