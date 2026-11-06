<?php

namespace App\Desk;

use App\Models\ProjectRequest;
use App\Models\Quote;
use App\Models\WebsiteOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The CrowdMerch Desk (Scott, 7 Nov 2026; docs/desk.md): every open job from CMAU_DEV in one list,
 * each with its next step. Quotes (website quote requests, instant quotes, phone and email quotes),
 * website orders and samples (CM-), and project and sourcing requests (PR-). Change requests show on
 * their order.
 */
class DeskJobs
{
    /** Need-by dates this close (days) mark a job urgent. */
    public const URGENT_DAYS = 14;

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        $tz = config('app.timezone');
        $jobs = collect();

        $orders = WebsiteOrder::query()->with(['emails', 'positions', 'changeRequests', 'lines'])
            ->where(fn ($q) => $q->where('stage', '<', WebsiteOrder::STAGE_DELIVERED)->orWhere('updated_at', '>=', now()->subDays(7)))
            ->latest('id')->limit(300)->get();
        foreach ($orders as $o) {
            $s = JobState::forOrder($o);
            $qty = $o->lines->sum('qty');
            $names = $o->lines->pluck('product_name')->unique()->take(3)->implode(', ');
            $open = $o->changeRequests->whereNull('handled_at')->first();
            $jobs->push($this->row($s, [
                'ref' => $o->number,
                'kind' => 'order',
                'source' => $open ? 'Change request · from My Orders' : ($o->isSamples() ? 'Samples order' : ($o->stage >= 2 ? 'Paid order · checkout' : 'Order · checkout')),
                'client' => $o->organisation ?: $o->contact_name,
                'summary' => $open ? '"'.mb_strimwidth($open->message, 0, 90, '…').'"' : trim(($qty ? "{$qty} × " : '').$names),
                'value' => (float) $o->total,
                'need_by' => $o->need_by,
                'since' => $o->stage_changed_at ?? $o->created_at,
                'url' => route('website-orders.show', $o),
            ]));
        }

        $quotes = Quote::query()->withCount('items')
            ->whereNotIn('status', ['SAVELIST'])
            ->where(fn ($q) => $q->whereNotIn('status', ['PAID', 'LOST'])->orWhere('updated_at', '>=', now()->subDays(7)))
            ->latest('id')->limit(300)->get();
        foreach ($quotes as $q) {
            $s = JobState::forQuote($q);
            $instant = $q->source === 'website' && $q->status === 'QUOTE';
            $jobs->push($this->row($s, [
                'ref' => $q->number,
                'kind' => 'quote',
                'source' => $q->source === 'website' ? ($instant ? 'Instant quote · PDF sent by the website' : 'Quote request · website') : 'Quote · '.($q->status === 'PHONE' ? 'phone' : 'staff'),
                'client' => $q->client_company ?: ($q->client_name ?: 'No client yet'),
                'summary' => trim(($q->title ?: '').($q->items_count ? ' · '.$q->items_count.' line'.($q->items_count > 1 ? 's' : '') : '')),
                'value' => (float) $q->total,
                'need_by' => $q->deadline,
                'since' => $q->sent_at ?? $q->created_at,
                'url' => route('quotes.show', $q),
            ]));
        }

        foreach (ProjectRequest::query()->where('status', 'new')->latest('id')->limit(100)->get() as $p) {
            $jobs->push([
                'ref' => $p->number, 'kind' => 'request', 'source' => 'Project or sourcing request',
                'client' => $p->organisation ?: $p->name(), 'summary' => mb_strimwidth($p->quantity.' · '.$p->brief, 0, 110, '…'),
                'value' => null, 'need_by' => $p->need_by, 'since' => $p->created_at,
                'bucket' => 'to_quote', 'needs' => true, 'title' => 'Reply by '.$p->reply_by?->format('D j M'),
                'action' => ['label' => 'Reply to request', 'url' => route('project-requests.show', $p)],
                'url' => route('project-requests.show', $p),
                'urgent' => $p->reply_by && $p->reply_by->lte(now()->endOfDay()),
                'when' => $this->ago($p->created_at, 'Asked'),
            ]);
        }

        return $jobs->sortBy([
            fn ($a, $b) => (int) $b['urgent'] <=> (int) $a['urgent'],
            fn ($a, $b) => (int) $b['needs'] <=> (int) $a['needs'],
            fn ($a, $b) => ($a['need_by']?->timestamp ?? PHP_INT_MAX) <=> ($b['need_by']?->timestamp ?? PHP_INT_MAX),
            fn ($a, $b) => ($a['since']?->timestamp ?? 0) <=> ($b['since']?->timestamp ?? 0),
        ])->values();
    }

    /** @param  array<string, mixed>  $base */
    private function row(array $s, array $base): array
    {
        $needBy = $base['need_by'];
        $urgent = $s['bucket'] !== 'done' && $needBy instanceof CarbonInterface && $needBy->lte(now()->addDays(self::URGENT_DAYS)->endOfDay());

        return $base + ['bucket' => $s['bucket'], 'needs' => $s['needs'], 'title' => $s['title'], 'action' => $s['action'],
            'urgent' => $urgent, 'when' => $this->ago($base['since'], match ($s['bucket']) {
                'quote_sent' => 'Sent', 'to_quote' => 'Asked', 'done' => 'Done', default => 'Since',
            })];
    }

    private function ago(?CarbonInterface $t, string $verb): string
    {
        return $t ? $verb.' '.$t->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW, 'short' => false, 'parts' => 1]) : '';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $jobs
     * @return Collection<int, array<string, mixed>>
     */
    public function filter(Collection $jobs, string $tab): Collection
    {
        return match ($tab) {
            'quotes' => $jobs->filter(fn ($j) => in_array($j['bucket'], ['to_quote', 'quote_sent'], true)),
            'orders' => $jobs->filter(fn ($j) => in_array($j['bucket'], ['to_invoice', 'awaiting_payment'], true)),
            'production' => $jobs->filter(fn ($j) => in_array($j['bucket'], ['proof_po', 'production'], true)),
            'waiting' => $jobs->filter(fn ($j) => ! $j['needs'] && in_array($j['bucket'], ['quote_sent', 'awaiting_payment'], true)),
            'done' => $jobs->where('bucket', 'done'),
            default => $jobs->filter(fn ($j) => $j['needs']),
        };
    }
}
