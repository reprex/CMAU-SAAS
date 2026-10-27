<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ShopifyDraftOrder;
use App\Quotes\DraftOrderIntake;
use App\Quotes\DraftOrderParser;

/** Draft orders that did not become quotes: anonymous wish lists, and errors. */
class DraftOrderController extends Controller
{
    public function index(DraftOrderParser $parser)
    {
        $drafts = ShopifyDraftOrder::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('anonymous', true)->whereNull('quote_id'))->orWhereNotNull('error'))
            ->latest('shopify_created_at')
            ->paginate(50);

        $byDay = ShopifyDraftOrder::query()->where('anonymous', true)
            ->where('shopify_created_at', '>=', now()->subDays(90))
            ->selectRaw('DATE(shopify_created_at) as day, count(*) as n')
            ->groupBy('day')->orderBy('day')->pluck('n', 'day');

        $drafts->getCollection()->transform(function (ShopifyDraftOrder $d) use ($parser) {
            $d->setAttribute('first_line', $parser->parse($d->data())['lines'][0]['title'] ?? null);

            return $d;
        });

        return view('drafts.index', compact('drafts', 'byDay'));
    }

    /** Staff open an anonymous wish list as a quote, or retry one that failed. */
    public function convert(ShopifyDraftOrder $draft, DraftOrderIntake $intake)
    {
        $quote = $intake->process($draft->fresh(), includeAnonymous: true);
        if ($quote === null) {
            return back()->withErrors(['draft' => 'Could not turn '.$draft->name.' into a quote: '.$draft->fresh()->error]);
        }
        AuditLog::record('draft.converted', $quote, ['draft' => $draft->name]);

        return redirect()->route('quotes.show', $quote);
    }
}
