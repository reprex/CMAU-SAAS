<?php

namespace App\Shopify;

use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How the SaaS → Shopify upload is going (Scott, 3 Nov 2026; docs/publisher.md), for Admin › Shopify
 * publishing and the warning banner: what's waiting, how many went up each minute and hour (from
 * shopify_publish_log), failures, a finish estimate, and whether it has stalled.
 */
class PublishHealth
{
    /** No successful upload for this long while products wait (and publishing is on) is a stall. */
    public const STALL_MINUTES = 10;

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $on = (bool) Cache::get('shopify:auto_publish', false);
        $paused = Cache::get('shopify:publish_paused_until');
        $heartbeat = Cache::get('scheduler:last_run');
        $cronOk = $heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(3));

        $live = fn () => Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false);
        $waiting = [
            'new' => $live()->whereNull('shopify_product_id')->count(),
            'changed' => $live()->whereNotNull('shopify_product_id')->where(fn ($w) => $w->whereNull('published_at')->orWhereColumn('range_changed_at', '>', 'published_at'))->count(),
            'archive' => Product::query()->whereNotNull('shopify_product_id')->where(fn ($w) => $w->where('tier', '!=', Product::TIER_LIVE)->orWhere('discontinued', true))->whereColumn('updated_at', '>', 'published_at')->count(),
        ];
        $onStore = $live()->whereNotNull('shopify_product_id')->count();

        $log = fn () => DB::table('shopify_publish_log');
        $lastOk = $log()->where('ok', true)->whereIn('action', ['create', 'update', 'archive'])->max('created_at');
        $last = $log()->max('created_at');

        // Per minute, last hour; per hour, last day. substr() on the stored timestamp works in MariaDB and SQLite.
        $minutes = $this->bucket(16, now()->subMinutes(60));
        $hours = $this->bucket(13, now()->subDay());

        $recent = $log()->where('created_at', '>=', now()->subMinutes(15));
        $done15 = (clone $recent)->where('ok', true)->whereIn('action', ['create', 'update', 'archive'])->selectRaw('action, count(*) n')->groupBy('action')->pluck('n', 'action');
        $failed15 = (clone $recent)->where('ok', false)->count();
        $rate = ['create' => ($done15['create'] ?? 0) / 15, 'update' => (($done15['update'] ?? 0) + ($done15['archive'] ?? 0)) / 15];
        $etaMinutes = null;
        $todo = $waiting['new'] + $waiting['changed'] + $waiting['archive'];
        if ($todo > 0) {
            $parts = [];
            if ($waiting['new']) {
                $parts[] = $rate['create'] > 0 ? $waiting['new'] / $rate['create'] : null;
            }
            if ($waiting['changed'] + $waiting['archive']) {
                $parts[] = $rate['update'] > 0 ? ($waiting['changed'] + $waiting['archive']) / $rate['update'] : null;
            }
            $etaMinutes = in_array(null, $parts, true) ? null : (int) ceil(array_sum($parts));
        }

        $failures = DB::table('shopify_publish_log as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.ok', false)->where('l.created_at', '>', now()->subDay())
            ->whereNotExists(fn ($q) => $q->from('shopify_publish_log as l2')->whereColumn('l2.product_id', 'l.product_id')->where('l2.ok', true)->whereColumn('l2.id', '>', 'l.id'))
            ->orderByDesc('l.id')->limit(200)->get(['l.product_id', 'l.action', 'l.message', 'l.created_at', 'p.name'])->unique('product_id')->values();

        $binding = app(StoreBinding::class);
        [$status, $headline] = match (true) {
            ! $binding->matches() => ['bad', 'The stored Shopify ids belong to '.$binding->bound().', not '.$binding->configured().': nothing can be published until `shopify:switch-store --apply` is run (docs/publisher.md).'],
            ! $cronOk => ['bad', 'The minute cron isn\'t running: nothing is being uploaded. Check the cPanel cron job.'],
            ! $on => [$todo ? 'warn' : 'ok', $todo ? 'Automatic publishing is off, '.number_format($todo).' products waiting.' : 'Automatic publishing is off. Nothing waiting.'],
            (bool) $paused => ['warn', 'Paused until '.Carbon::parse($paused)->format('j M H:i').': Shopify\'s daily limit on new variants.'],
            $todo > 0 && (! $lastOk || Carbon::parse($lastOk)->lt(now()->subMinutes(self::STALL_MINUTES))) => ['bad', 'Stalled: nothing uploaded for '.($lastOk ? Carbon::parse($lastOk)->diffForHumans(null, true) : 'a while').' with '.number_format($todo).' waiting.'],
            $failed15 > 0 && $failed15 >= array_sum($done15->all()) => ['bad', 'Most uploads in the last 15 minutes failed ('.$failed15.'). See the failures below.'],
            $todo > 0 => ['ok', 'Uploading: '.number_format(array_sum($done15->all()) / 15, 1).' products a minute (last 15 minutes).'],
            default => ['ok', 'Up to date: everything on the range is on Shopify.'],
        };

        return compact('on', 'paused', 'cronOk', 'heartbeat', 'waiting', 'todo', 'onStore', 'lastOk', 'last', 'minutes', 'hours', 'done15', 'failed15', 'rate', 'etaMinutes', 'failures', 'status', 'headline')
            + ['store' => (string) config('shopify.store')];
    }

    /** A short status for the staff banner, cached for a minute. @return array{status: string, headline: string}|null */
    public function banner(): ?array
    {
        $s = Cache::remember('shopify:publish-health:banner', 60, function () {
            $s = $this->summary();

            return ['status' => $s['status'], 'headline' => $s['headline'], 'on' => $s['on']];
        });

        return $s['on'] && $s['status'] === 'bad' ? $s : null;
    }

    /**
     * Uploads per time bucket: [bucket => [create, update, archive, failed]].
     *
     * @return array<string, array{create: int, update: int, archive: int, failed: int}>
     */
    private function bucket(int $chars, Carbon $since): array
    {
        $rows = DB::table('shopify_publish_log')->where('created_at', '>=', $since)
            ->selectRaw("substr(created_at, 1, {$chars}) as b, action, ok, count(*) as n")->groupBy('b', 'action', 'ok')->get();
        $out = [];
        $step = $chars === 16 ? 'addMinute' : 'addHour';
        $format = $chars === 16 ? 'Y-m-d H:i' : 'Y-m-d H';
        for ($t = $since->copy()->startOfMinute(); $t->lte(now()); $t->{$step}()) {
            $out[$t->format($format)] = ['create' => 0, 'update' => 0, 'archive' => 0, 'failed' => 0];
        }
        foreach ($rows as $r) {
            if (! isset($out[$r->b])) {
                continue;
            }
            if (! $r->ok) {
                $out[$r->b]['failed'] += (int) $r->n;
            } elseif (isset($out[$r->b][$r->action])) {
                $out[$r->b][$r->action] += (int) $r->n;
            }
        }

        return $out;
    }
}
