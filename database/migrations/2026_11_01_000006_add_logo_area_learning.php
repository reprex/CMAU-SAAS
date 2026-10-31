<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo areas learned from a vetted sample (docs/logo-preview.md): every hero
 * image keeps its prediction (Claude or the type default); staff vet a sample
 * of about 100; the corrections they make are applied to the rest of the range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_areas', function (Blueprint $table) {
            $table->decimal('pred_left', 5, 2)->nullable()->after('width');
            $table->decimal('pred_top', 5, 2)->nullable()->after('pred_left');
            $table->decimal('pred_width', 5, 2)->nullable()->after('pred_top');
            $table->string('group_key', 160)->nullable()->index()->after('product_id'); // supplier|product type
            $table->boolean('sample')->default(false)->index()->after('source');
            $table->string('learned_from', 12)->nullable()->after('sample');          // group | type | all
        });
    }

    public function down(): void
    {
        Schema::table('print_areas', function (Blueprint $table) {
            $table->dropColumn(['pred_left', 'pred_top', 'pred_width', 'group_key', 'sample', 'learned_from']);
        });
    }
};
