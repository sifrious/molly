<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('molly_tasks', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('id');
            $table->uuid('workspace_id')->nullable()->after('project_id');
            $table->uuid('repository_id')->nullable()->after('workspace_id');
            $table->string('repository_remote_identity')->nullable()->after('repository_id');
            $table->uuid('checkout_id')->nullable()->after('repository_remote_identity');
            $table->string('checkout_kind')->nullable()->after('checkout_id');
            $table->string('base_sha', 40)->nullable()->after('checkout_kind');
            $table->string('branch')->nullable()->after('base_sha');
            $table->uuid('bloom_workspace_id')->nullable()->after('branch');
            $table->string('identity_status')->default('legacy_path')->after('bloom_workspace_id');
            // workspace column remains; treat as optional observed path going forward
        });

        Schema::table('molly_runs', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('id');
            $table->uuid('workspace_id')->nullable()->after('project_id');
            $table->uuid('repository_id')->nullable()->after('workspace_id');
            $table->string('repository_remote_identity')->nullable()->after('repository_id');
            $table->uuid('checkout_id')->nullable()->after('repository_remote_identity');
            $table->string('checkout_kind')->nullable()->after('checkout_id');
            $table->string('base_sha', 40)->nullable()->after('checkout_kind');
            $table->string('branch')->nullable()->after('base_sha');
            $table->uuid('bloom_workspace_id')->nullable()->after('branch');
            $table->string('identity_status')->default('legacy_path')->after('bloom_workspace_id');
        });
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
