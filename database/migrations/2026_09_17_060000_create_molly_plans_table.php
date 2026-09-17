<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molly_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('description');
            $table->string('review_mode');
            $table->json('answers');
            $table->json('suggestion')->nullable();
            $table->string('guide_version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molly_plans');
    }
};
