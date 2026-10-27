<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing tables, mirroring TOD:
 *  - dec_type_codes        (TOD: DecTypeCode)            how each decoration description is treated
 *  - price_options         (TOD: dbo_PDS_CostPriceSummary) computed cost/price strings per product option
 *  - price_option_settings (TOD: dbo_PDS_LocalPrice)       staff choices that survive recalculation
 *  - product_web_prices    (TOD: web fields on dbo_PDS_Products) derived web values incl. DecorationJSON
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dec_type_codes', function (Blueprint $table) {
            $table->string('code')->primary();            // lower-case alphanumeric description
            $table->unsignedTinyInteger('type_code')->nullable(); // 10 dec, 11 acc, 12 undec, 23 hide; null = to classify
            $table->string('overwrite_description')->nullable();
            $table->string('full_description', 1024)->nullable();
            $table->string('promodata_decoration')->nullable();
            $table->unsignedBigInteger('first_supplier_id')->nullable();
            $table->string('first_supplier_name')->nullable();
            $table->boolean('spot_colour')->default(false);
            $table->boolean('full_colour')->default(false);
            $table->boolean('embroider')->default(false);
            $table->boolean('engrave')->default(false);
            $table->boolean('emboss_deboss')->default(false);
            $table->boolean('foil_stamp')->default(false);
            $table->boolean('sublimation')->default(false);
            $table->timestamps();

            $table->index('type_code');
        });

        Schema::create('price_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('option_id')->unique();        // "APPAID-code" (TOD TextPDID)
            $table->string('code');
            $table->string('kind', 16);                   // UNDEC | PDADEC | EXT
            $table->unsignedTinyInteger('type_code')->nullable()->index();
            $table->string('description', 1024);
            $table->string('display_description', 1024)->nullable(); // overwrite text, else description
            $table->decimal('setup_cost', 12, 4)->default(0);
            $table->decimal('setup_price', 12, 4)->default(0);
            $table->text('cost_string')->nullable();
            $table->text('price_string')->nullable();
            $table->string('promodata_decoration')->nullable();
            $table->boolean('placeholder')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('price_option_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('option_id')->unique();
            $table->unsignedTinyInteger('type_code_override')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_other')->default(false);
            // Display group in DecorationJSON (TOD: CPS DecJSONSort): lower first,
            // then by price at 50 + setup. Null means 2 (normal); 1 lists first;
            // supplier price files (e.g. Melbourne Merch) set their own order.
            $table->unsignedTinyInteger('json_sort')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_web_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->unsignedBigInteger('decoration_product_id'); // TOD WebDecLiveAPPAID (self unless an external decoration is used)
            $table->string('decoration_source', 16)->nullable();  // MANUAL | EXTERNAL | AUTO
            $table->string('undecorated_option_id')->nullable();
            $table->string('default_decoration_option_id')->nullable();
            $table->boolean('decoration_available')->default(false);
            $table->boolean('zero_price_decoration')->default(false);
            $table->unsignedInteger('undecorated_moq')->nullable();
            $table->unsignedInteger('decoration_moq')->nullable();
            $table->unsignedInteger('actual_moq')->nullable();
            $table->unsignedInteger('collection_moq')->nullable();
            $table->decimal('order_value_100', 12, 4)->nullable();
            $table->decimal('order_value_moq', 12, 4)->nullable();
            $table->decimal('order_value_display', 12, 4)->nullable();
            $table->decimal('collection_variant_price', 12, 4)->nullable();
            $table->unsignedInteger('unbranded_option_count')->default(0);
            $table->unsignedInteger('decoration_option_count')->default(0);
            $table->longText('decoration_json')->nullable();
            $table->text('accessory_list')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_web_prices');
        Schema::dropIfExists('price_option_settings');
        Schema::dropIfExists('price_options');
        Schema::dropIfExists('dec_type_codes');
    }
};
