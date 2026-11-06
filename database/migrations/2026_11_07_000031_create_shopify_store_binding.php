<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which Shopify store the stored ids belong to (docs/publisher.md, "Switching to the live store").
 * Product, picture and collection ids are per store: the SaaS refuses to write to a store other
 * than the one its ids came from until `shopify:switch-store --apply` has moved them aside.
 * The ids of a store switched away from are kept in shopify_store_archive, so switching back
 * (e.g. to CMAU_DEV) restores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('shopify_store_archive', function (Blueprint $table) {
            $table->id();
            $table->string('store', 120)->index();
            $table->string('kind', 20);              // product | media | collection
            $table->string('ref', 191);              // product id, product id + url hash, product type
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['store', 'kind']);
        });

        // Ids already stored came from the development store (writes were locked to it), even if .env
        // has already been pointed at the live store when this runs.
        $store = mb_strtolower((string) (config('shopify.dev_store') ?: config('shopify.store')));
        $hasIds = DB::table('products')->whereNotNull('shopify_product_id')->exists()
            || DB::table('shopify_media')->exists()
            || DB::table('shopify_collections')->whereNotNull('shopify_gid')->exists();
        if ($store !== '' && $hasIds) {
            DB::table('app_settings')->insert(['key' => 'shopify.ids_store', 'value' => $store, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_store_archive');
        Schema::dropIfExists('app_settings');
    }
};
