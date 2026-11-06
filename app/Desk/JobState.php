<?php

namespace App\Desk;

use App\Models\PendingEmail;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\WebsiteOrder;

/**
 * Where a CrowdMerch job is and the one thing it needs next (Scott, 7 Nov 2026; docs/desk.md). Used by
 * the Desk list and by the "Next step" card at the top of each order and quote.
 *
 * Buckets: to_quote, quote_sent, to_invoice, awaiting_payment, proof_po, production, done.
 * `needs` is true when staff have to do something now (the Desk's "Needs me" list).
 */
class JobState
{
    public const BUCKETS = [
        'to_quote' => 'To quote',
        'quote_sent' => 'Quote sent, waiting',
        'to_invoice' => 'To invoice',
        'awaiting_payment' => 'Waiting for payment',
        'proof_po' => 'Proof & purchase orders',
        'production' => 'In production / shipping',
    ];

    /** Days after sending a quote before the Desk suggests a follow-up. */
    public const FOLLOW_UP_DAYS = 3;

    /**
     * @return array{steps: list<string>, now: int, bucket: string, needs: bool, title: string, text: string,
     *   checks: list<array{0: bool, 1: string}>, action: ?array{label: string, url: string, post?: bool}}
     */
    public static function forOrder(WebsiteOrder $o): array
    {
        $samples = $o->isSamples();
        $stages = $samples ? array_values($o->stageOptions()) : WebsiteOrder::STAGES;
        $now = $samples ? (int) array_search($o->stage, WebsiteOrder::SAMPLE_STAGES, true) : (int) $o->stage;
        $show = route('website-orders.show', $o);
        $draft = $o->emails->first(fn (PendingEmail $e) => $e->kind === PendingEmail::KIND_INVOICE && in_array($e->status, ['draft', 'failed'], true));
        $pos = PurchaseOrder::query()->where('website_order_id', $o->id)->get();
        $unsent = $pos->where('status', '!=', 'sent');
        $logosHelp = $o->positions->where('preflight', 'help')->count();
        $alerts = count((array) $o->alerts);
        $openChanges = $o->changeRequests->whereNull('handled_at')->count();
        $checks = [];
        $state = ['steps' => $stages, 'now' => $now];

        switch (true) {
            case $o->stage === 0:
                $checks = [[$alerts === 0, $alerts ? "{$alerts} pre-payment alert".($alerts > 1 ? 's' : '').' on the invoice' : 'No pre-payment alerts'],
                    [$logosHelp === 0, $logosHelp ? "{$logosHelp} logo".($logosHelp > 1 ? 's need' : ' needs').' artwork help' : 'Logos checked']];
                $state += ['bucket' => 'to_invoice', 'needs' => true, 'title' => 'Send the invoice',
                    'text' => 'The invoice PDF and email are ready. Check them, then send.',
                    'action' => $draft ? ['label' => 'Review & send invoice', 'url' => route('emails.review', $draft)] : ['label' => 'Invoice PDF', 'url' => route('website-orders.invoice', $o)]];
                break;
            case $o->stage === 1:
                $state += ['bucket' => 'awaiting_payment', 'title' => 'Waiting for payment',
                    'text' => 'Invoice sent'.($o->invoice_sent_at ? ' '.$o->invoice_sent_at->timezone(config('app.timezone'))->format('j M') : '').'. Set the stage to Paid when the money is in.',
                    'action' => ['label' => 'Update progress', 'url' => $show.'#progress']];
                break;
            case $samples && $o->stage < WebsiteOrder::STAGE_DISPATCHED:
                $state += ['bucket' => 'production', 'needs' => true, 'title' => 'Order the samples',
                    'text' => 'Paid. Ask each factory for the samples, then add the carrier and consignment when they ship.',
                    'action' => ['label' => 'Update progress', 'url' => $show.'#progress']];
                break;
            case $o->stage === 2 || ($o->stage === 3 && ($pos->isEmpty() || $unsent->isNotEmpty())):
                $checks = [[$logosHelp === 0, $logosHelp ? "{$logosHelp} logo".($logosHelp > 1 ? 's need' : ' needs').' artwork help' : 'Logos checked'],
                    [$pos->isNotEmpty(), $pos->isEmpty() ? 'No purchase order yet' : $pos->count().' purchase order'.($pos->count() > 1 ? 's' : '')],
                ];
                if ($pos->isNotEmpty()) {
                    $checks[] = [$unsent->isEmpty(), $unsent->isEmpty() ? 'All sent to suppliers' : $unsent->count().' not sent yet'];
                }
                $title = $o->stage === 2 ? 'Send the proof and the purchase orders' : 'Send the purchase orders';
                $text = $o->stage === 2 ? 'Paid. Send the client the digital proof, and the purchase orders to the suppliers. Set the stage to Proof approved when the client approves.' : 'Proof approved. Send the remaining purchase orders so production can start.';
                $first = $unsent->first();
                $action = $pos->isEmpty() ? ['label' => 'Create purchase order', 'url' => route('purchase-orders.store', $o), 'post' => true]
                    : ($first ? ['label' => 'Review & send '.$first->number, 'url' => route('purchase-orders.review', $first), 'post' => true] : ['label' => 'Update progress', 'url' => $show.'#progress']);
                $state += ['bucket' => 'proof_po', 'needs' => true, 'title' => $title, 'text' => $text, 'action' => $action];
                break;
            case $o->stage < WebsiteOrder::STAGE_DISPATCHED:
                $noFreight = $pos->whereNull('freight_cost')->count();
                $checks = [[$noFreight === 0, $noFreight ? "Supplier freight to add on {$noFreight} PO".($noFreight > 1 ? 's' : '') : 'Supplier freight added']];
                $state += ['bucket' => 'production', 'title' => 'In production',
                    'text' => 'When the goods ship, add the carrier and consignment number and set the stage to Dispatched. The client sees tracking in My Orders.',
                    'action' => ['label' => 'Add tracking', 'url' => $show.'#progress']];
                break;
            case $o->stage === WebsiteOrder::STAGE_DISPATCHED:
                $state += ['bucket' => 'production', 'title' => 'On its way',
                    'text' => 'Set the stage to Delivered, with the date, when it arrives.',
                    'action' => ['label' => 'Mark delivered', 'url' => $show.'#progress']];
                break;
            default:
                $state += ['bucket' => 'done', 'title' => 'Delivered', 'text' => 'Nothing left to do.'];
        }
        $state += ['needs' => false, 'action' => null];
        if ($openChanges) {
            array_unshift($checks, [false, "{$openChanges} open change request".($openChanges > 1 ? 's' : '')]);
            $state['needs'] = true;
            if ($state['bucket'] !== 'to_invoice') {
                $state['action'] = ['label' => 'Answer change request', 'url' => $show.'#changes'];
            }
        }
        $state['checks'] = $checks;

        return $state;
    }

    /** Quotes use TOD's statuses (Quote::STATUSES). */
    public static function forQuote(Quote $q): array
    {
        $steps = ['Request in', 'Quote sent', 'Won', 'Proof', 'Shipped', 'Invoiced', 'Paid'];
        $now = match ($q->status) {
            'QUOTE' => 1, 'ORDER' => 2, 'PROOF SENT', 'PROOF OK' => 3, 'SHIPPED' => 4, 'INVOICED' => 5, 'PAID' => 6, default => 0,
        };
        $show = route('quotes.show', $q);
        $state = ['steps' => $steps, 'now' => $now];
        $items = $q->relationLoaded('items') ? $q->items->count() : $q->items()->count();

        if ($q->status === 'LOST') {
            return $state + ['bucket' => 'done', 'needs' => false, 'checks' => [], 'title' => 'Lost', 'text' => 'Marked lost.', 'action' => null];
        }
        if ($now === 0) {
            $checks = [[$items > 0, $items ? "{$items} line".($items > 1 ? 's' : '').' priced by the SaaS' : 'No lines yet'],
                [filled($q->client_email), filled($q->client_email) ? 'Client email '.$q->client_email : 'No client email'],
                [(float) $q->total > 0, (float) $q->total > 0 ? 'Total $'.number_format((float) $q->total, 2) : 'No total yet']];

            return $state + ['bucket' => 'to_quote', 'needs' => true, 'checks' => $checks,
                'title' => 'Check the quote, then send it',
                'text' => 'Check the lines, delivery and quote PDF, then review the email.',
                'action' => ['label' => 'Review & send quote', 'url' => route('quotes.email', $q), 'post' => true]];
        }
        if ($now === 1) {
            $days = $q->sent_at ? (int) $q->sent_at->diffInDays(now()) : 0;
            $late = $days >= self::FOLLOW_UP_DAYS;

            return $state + ['bucket' => 'quote_sent', 'needs' => $late, 'checks' => [],
                'title' => $late ? 'Follow up the quote' : 'Waiting on the client',
                'text' => $q->sent_at ? 'Quote sent '.$q->sent_at->timezone(config('app.timezone'))->format('j M').($late ? ", {$days} days ago. A short follow-up email or call?" : '.') : 'Quote sent.',
                'action' => ['label' => $late ? 'Follow up' : 'Open quote', 'url' => $show]];
        }
        if ($now === 6) {
            return $state + ['bucket' => 'done', 'needs' => false, 'checks' => [], 'title' => 'Paid', 'text' => 'Nothing left to do.', 'action' => null];
        }

        return $state + ['bucket' => $now === 5 ? 'awaiting_payment' : 'production', 'needs' => false, 'checks' => [], 'title' => Quote::STATUSES[$q->status] ?? $q->status,
            'text' => 'Move the status on as the job progresses.', 'action' => ['label' => 'Update status', 'url' => $show]];
    }
}
