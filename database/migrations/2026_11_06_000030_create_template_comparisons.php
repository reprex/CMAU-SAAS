<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A second reading of a template by another model, kept beside the one in use to compare (Scott, 6 Nov 2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branding_template_id')->constrained()->cascadeOnDelete();
            $table->string('model', 64);
            $table->json('positions')->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamps();
            $table->unique(['branding_template_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_comparisons');
    }
};
