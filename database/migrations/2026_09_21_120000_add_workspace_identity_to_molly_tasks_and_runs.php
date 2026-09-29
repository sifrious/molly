<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each column is its own statement, so an interrupted migrate can leave some of them
        // behind. Running it again adds only what is missing. The workspace column remains;
        // treat it as the optional observed path from here on.
        foreach (['molly_tasks', 'molly_runs'] as $name) {
            $columns = [
                'project_id' => fn (Blueprint $table) => $table->uuid('project_id')->nullable()->after('id'),
                'workspace_id' => fn (Blueprint $table) => $table->uuid('workspace_id')->nullable()->after('project_id'),
                'repository_id' => fn (Blueprint $table) => $table->uuid('repository_id')->nullable()->after('workspace_id'),
                'repository_remote_identity' => fn (Blueprint $table) => $table->string('repository_remote_identity')->nullable()->after('repository_id'),
                'checkout_id' => fn (Blueprint $table) => $table->uuid('checkout_id')->nullable()->after('repository_remote_identity'),
                'checkout_kind' => fn (Blueprint $table) => $table->string('checkout_kind')->nullable()->after('checkout_id'),
                'base_sha' => fn (Blueprint $table) => $table->string('base_sha', 40)->nullable()->after('checkout_kind'),
                'branch' => fn (Blueprint $table) => $table->string('branch')->nullable()->after('base_sha'),
                'bloom_workspace_id' => fn (Blueprint $table) => $table->uuid('bloom_workspace_id')->nullable()->after('branch'),
                'identity_status' => fn (Blueprint $table) => $table->string('identity_status')->default('legacy_path')->after('bloom_workspace_id'),
            ];
            foreach ($columns as $column => $add) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, $add);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'project_id', 'workspace_id', 'repository_id', 'repository_remote_identity',
                'checkout_id', 'checkout_kind', 'base_sha', 'branch', 'bloom_workspace_id', 'identity_status',
            ]);
        });
        Schema::table('molly_runs', function (Blueprint $table) {
            $table->dropColumn([
                'project_id', 'workspace_id', 'repository_id', 'repository_remote_identity',
                'checkout_id', 'checkout_kind', 'base_sha', 'branch', 'bloom_workspace_id', 'identity_status',
            ]);
        });
    }
};
