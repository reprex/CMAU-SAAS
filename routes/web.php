<?php

use App\Http\Controllers\Admin\CatalogueSyncController;
use App\Http\Controllers\Admin\DecorationTypeController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DraftOrderController;
use App\Http\Controllers\LineTransferController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ProductLookupController;
use App\Http\Controllers\Public\ShopifyWebhookController;
use App\Http\Controllers\Public\StockRelayController;
use App\Http\Controllers\Public\WebsiteQuoteController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteExtraController;
use App\Http\Controllers\QuoteItemController;
use App\Http\Controllers\RangeController;
use App\Http\Controllers\SubstituteController;
use Illuminate\Support\Facades\Route;

// ---- Public endpoints (no login) -----------------------------------------

// CMAU product page "Check stock" relay; keeps the Promodata token on the server.
Route::match(['GET', 'POST', 'OPTIONS'], '/stock.php', StockRelayController::class)->name('stock.relay');
Route::match(['GET', 'POST', 'OPTIONS'], '/stock', StockRelayController::class);

Route::post('/webhooks/shopify/draft-orders', [ShopifyWebhookController::class, 'draftOrders'])->name('webhooks.shopify.drafts');

// Website quote API (same paths the theme uses on app.crowdmerch.com.au).
Route::prefix('crowdmerch/homepage')->controller(WebsiteQuoteController::class)->group(function () {
    Route::options('{any}', 'preflight')->where('any', '.*');
    Route::post('createQuoteDraftOrder', 'createQuoteDraftOrder');
    Route::post('addToWishlist', 'addToWishlist');
    Route::match(['GET', 'POST'], 'getDraftOrderItems', 'getDraftOrderItems');
    Route::post('removeDiscontinuedItem', 'removeDiscontinuedItem');
});

// ---- Sign-in --------------------------------------------------------------

Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/setup', [TwoFactorController::class, 'confirm']);
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verifyChallenge']);
});

// ---- Staff app ------------------------------------------------------------

Route::middleware(['auth', 'two-factor'])->group(function () {
    Route::redirect('/', '/quotes');

    Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote')->name('quotes.show');
    Route::get('/quotes/{quote}/pdf', [PdfController::class, 'quote'])->name('quotes.pdf');
    Route::get('/quotes/{quote}/comparison.pdf', [PdfController::class, 'comparison'])->name('quotes.comparison');
    Route::get('/drafts', [DraftOrderController::class, 'index'])->name('drafts.index');
    Route::get('/products/search', [ProductLookupController::class, 'search'])->name('products.search');
    Route::get('/products/{product}/options', [ProductLookupController::class, 'options'])->name('products.options');
    Route::get('/items/{item}/skus', [QuoteItemController::class, 'skuList'])->name('items.skus');
    Route::get('/range', [RangeController::class, 'index'])->name('range.index');
    Route::get('/range/{product}', [RangeController::class, 'show'])->whereNumber('product')->name('range.show');
    Route::get('/substitutes', [SubstituteController::class, 'lookup'])->name('substitutes.lookup');
    Route::get('/range/{product}/substitutes', [SubstituteController::class, 'show'])->whereNumber('product')->name('range.substitutes');
    Route::get('/range/{product}/stock', [SubstituteController::class, 'stock'])->whereNumber('product')->name('range.stock');

    Route::middleware('role:edit')->group(function () {
        Route::get('/quotes/new', [QuoteController::class, 'create'])->name('quotes.create');
        Route::post('/quotes', [QuoteController::class, 'store'])->name('quotes.store');
        Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
        Route::post('/quotes/{quote}/status', [QuoteController::class, 'status'])->name('quotes.status');
        Route::post('/quotes/{quote}/recalculate', [QuoteController::class, 'recalculate'])->name('quotes.recalculate');
        Route::post('/quotes/{quote}/revise', [QuoteController::class, 'revise'])->name('quotes.revise');
        Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');

        Route::post('/quotes/{quote}/items', [QuoteItemController::class, 'store'])->name('items.store');
        Route::put('/items/{item}', [QuoteItemController::class, 'update'])->name('items.update');
        Route::delete('/items/{item}', [QuoteItemController::class, 'destroy'])->name('items.destroy');
        Route::post('/items/{item}/duplicate', [QuoteItemController::class, 'duplicate'])->name('items.duplicate');
        Route::post('/items/{item}/separate', [QuoteItemController::class, 'separate'])->name('items.separate');
        Route::post('/items/{item}/combine', [QuoteItemController::class, 'combine'])->name('items.combine');
        Route::post('/items/{item}/skus', [QuoteItemController::class, 'saveSkus'])->name('items.skus.save');
        Route::post('/items/{item}/transfer', [LineTransferController::class, 'item'])->name('items.transfer');
        Route::post('/extras/{extra}/transfer', [LineTransferController::class, 'extra'])->name('extras.transfer');

        Route::post('/quotes/{quote}/extras', [QuoteExtraController::class, 'store'])->name('extras.store');
        Route::put('/extras/{extra}', [QuoteExtraController::class, 'update'])->name('extras.update');
        Route::post('/extras/{extra}/reset', [QuoteExtraController::class, 'reset'])->name('extras.reset');
        Route::delete('/extras/{extra}', [QuoteExtraController::class, 'destroy'])->name('extras.destroy');

        Route::post('/drafts/{draft}/convert', [DraftOrderController::class, 'convert'])->name('drafts.convert');

        Route::post('/range/bulk', [RangeController::class, 'bulk'])->name('range.bulk');
        Route::put('/range/{product}', [RangeController::class, 'update'])->name('range.update');
        Route::put('/range/{product}/options', [RangeController::class, 'options'])->name('range.options');
        Route::post('/range/{product}/action', [RangeController::class, 'action'])->name('range.action');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

        Route::get('/promodata', [CatalogueSyncController::class, 'index'])->name('sync.index');
        Route::post('/promodata/check', [CatalogueSyncController::class, 'check'])->name('sync.check');
        Route::post('/promodata/runs', [CatalogueSyncController::class, 'start'])->name('sync.start');
        Route::post('/promodata/runs/{run}/cancel', [CatalogueSyncController::class, 'cancel'])->name('sync.cancel');
        Route::post('/promodata/product', [CatalogueSyncController::class, 'refreshProduct'])->name('sync.product');

        Route::get('/decoration-types', [DecorationTypeController::class, 'index'])->name('dectypes.index');
        Route::put('/decoration-types/{decorationType}', [DecorationTypeController::class, 'update'])->name('dectypes.update');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-2fa');
    });
});
