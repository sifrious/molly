<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('molly_seam_revisions')) {
            $this->create();
        }
        foreach ([['plan_id'], ['task_id'], ['plan_id', 'seam_id', 'number'], ['plan_id', 'seam_id', 'digest']] as $columns) {
            $unique = count($columns) > 1;
            if (! Schema::hasIndex('molly_seam_revisions', $columns, $unique ? 'unique' : null)) {
                Schema::table('molly_seam_revisions', function (Blueprint $table) use ($columns, $unique) {
                    $unique ? $table->unique($columns) : $table->index($columns);
                });
            }
        }
    }

    private function create(): void
    {
        Schema::create('molly_seam_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id')->index();
            $table->string('seam_id');
            $table->unsignedInteger('number');
            $table->string('digest', 64);
            $table->longText('snapshot');
            $table->uuid('task_id')->nullable()->index();
            $table->uuid('active_run_id')->nullable();
            $table->string('status')->default('preview');
            $table->unsignedInteger('cursor')->default(0);
            $table->json('results');
            $table->timestamps();
            $table->unique(['plan_id', 'seam_id', 'number']);
            $table->unique(['plan_id', 'seam_id', 'digest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molly_seam_revisions');
    }
};
