<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('molly_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('prompt');
            $table->text('workspace');
            $table->string('status');
            $table->json('report');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('molly_runs');
    }
};
