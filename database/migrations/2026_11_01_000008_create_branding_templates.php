<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier branding templates (docs/branding-templates.md): the supplier's own
 * PDF or line drawing for a product, read by Claude into branding positions
 * (name, view, maximum print size, where it sits on our product pictures).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('url', 1024);
            $table->string('source', 12)->default('promodata'); // promodata | supplier | staff
            $table->string('status', 10)->default('new');       // new | read | none | failed
            $table->string('message', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        Schema::create('branding_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->foreignId('branding_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name', 60);                  // Front centre, Left chest, Back, Right sleeve, Lid...
            $table->string('view', 12)->default('front'); // front | back | side | top
            $table->string('image_url', 1024)->nullable(); // which product picture shows this view
            $table->decimal('left', 5, 2)->nullable();   // % of that picture
            $table->decimal('top', 5, 2)->nullable();
            $table->decimal('width', 5, 2)->nullable();
            $table->unsignedSmallInteger('max_width_mm')->nullable();
            $table->unsignedSmallInteger('max_height_mm')->nullable();
            $table->json('methods')->nullable();          // decoration methods the template names for it
            $table->string('source', 10)->default('template'); // template | staff
            $table->timestamps();
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('template_url_pattern', 500)->nullable(); // e.g. https://supplier.com/templates/{code}.pdf
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_positions');
        Schema::dropIfExists('branding_templates');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('template_url_pattern');
        });
    }
};
