<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Shopify\PublishHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Admin › Shopify publishing: the upload health check (docs/publisher.md). */
class ShopifyPublishingController extends Controller
{
    public function index(PublishHealth $health)
    {
        return view('admin.shopify-publishing', ['h' => $health->summary()]);
    }

    /** Same switch as shopify:auto-publish on|off. */
    public function toggle(Request $request)
    {
        $on = $request->boolean('on');
        Cache::forever('shopify:auto_publish', $on);
        if ($on) {
            Cache::forget('shopify:publish_paused_until');
        }
        Cache::forget('shopify:publish-health:banner');
        AuditLog::record('shopify.auto_publish', null, ['on' => $on]);

        return back()->with('status', 'Automatic publishing is '.($on ? 'on' : 'off').'.');
    }
}
