<?php

namespace App\Catalogue;

use App\Jobs\RangeBulkAction;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RangeSuggestion;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Range › Changes to vet (Scott, 3 Nov 2026; docs/range.md). Finds the changes, gets them ready, and
 * lists the open quotes they affect:
 *
 *  - new: products first seen after a supplier was set to suggest its new products (product types as
 *    ticked). Each is fetched from Promodata and priced (kept tier, not live), then checked against
 *    similar products on the range (ReadinessCheck). It goes on the range only when staff approve.
 *  - discontinued: products the supplier discontinued that are on the range, or in a quote from the
 *    last two months that hasn't been ordered. The publisher archives range ones on Shopify as usual
 *    (so nobody orders them); this list is so staff offer those clients a substitute.
 */
class RangeChanges
{
    /** Quotes newer than this, not yet ordered, are listed against a discontinued product. */
    public const QUOTE_MONTHS = 2;

    /** Quote statuses that mean not yet ordered (and not lost). */
    public const OPEN_STATUSES = ['NEW', 'SAVELIST', 'PHONE', 'QUOTE'];

    public function __construct(private readonly RangeManager $range, private readonly ReadinessCheck $readiness, private readonly SubstituteFinder $substitutes) {}

    /** Add rows for changes not on the list yet. @return array{new: int, discontinued: int} */
    public function find(): array
    {
        $new = 0;
        foreach (Supplier::query()->where('suggest_new', true)->get() as $supplier) {
            $types = array_values(array_filter((array) $supplier->suggest_types, fn ($t) => $t !== null));
            $ids = Product::query()->where('supplier_id', $supplier->id)->where('discontinued', false)
                ->where('tier', '!=', Product::TIER_LIVE)
                ->where('created_at', '>', $supplier->suggest_since ?? now())
                ->when($types !== [], fn ($q) => $q->whereIn('promodata_type_name', $types))
                ->whereNotIn('id', RangeSuggestion::query()->where('kind', RangeSuggestion::NEW)->select('product_id'))
                ->limit(500)->pluck('id');
            foreach ($ids as $id) {
                RangeSuggestion::query()->create(['kind' => RangeSuggestion::NEW, 'product_id' => $id, 'supplier_id' => $supplier->id, 'status' => RangeSuggestion::PREPARING]);
                $new++;
            }
        }

        $quoted = QuoteItem::query()->whereNotNull('product_id')->whereIn('quote_id', $this->openQuotes()->select('id'))->select('product_id');
        $gone = Product::query()->where('discontinued', true)
            ->where(fn ($q) => $q->where('tier', Product::TIER_LIVE)->orWhereNotNull('shopify_product_id')->orWhereIn('id', $quoted))
            ->whereNotIn('id', RangeSuggestion::query()->where('kind', RangeSuggestion::DISCONTINUED)->select('product_id'))
            ->limit(500)->get(['id', 'supplier_id']);
        foreach ($gone as $p) {
            RangeSuggestion::query()->create(['kind' => RangeSuggestion::DISCONTINUED, 'product_id' => $p->id, 'supplier_id' => $p->supplier_id, 'status' => RangeSuggestion::PREPARING]);
        }

        return ['new' => $new, 'discontinued' => $gone->count()];
    }

    /** Fetch, price and check a few waiting rows (one Promodata call each for new products). */
    public function prepare(int $limit = 10): int
    {
        $done = 0;
        foreach (RangeSuggestion::query()->where('status', RangeSuggestion::PREPARING)->orderBy('id')->limit($limit)->get() as $s) {
            $this->recheck($s);
            $done++;
        }

        return $done;
    }

    /** Re-run the checks (prices and the range change): pending rows nightly, or one on request. */
    public function recheck(RangeSuggestion $s): RangeSuggestion
    {
        $product = Product::query()->find($s->product_id);
        if (! $product) {
            $s->update(['status' => RangeSuggestion::DECLINED, 'error' => 'No longer in the catalogue.']);

            return $s;
        }
        try {
            if ($s->kind === RangeSuggestion::DISCONTINUED) {
                $subs = $this->substitutes->find($product, ['limit' => 3])->map(fn ($r) => $r['product']->id)->values()->all();
                $s->update(['status' => $s->status === RangeSuggestion::PREPARING ? RangeSuggestion::PENDING : $s->status, 'ready' => false,
                    'checks' => ['substitutes' => $subs, 'was_live' => $product->tier === Product::TIER_LIVE || $product->shopify_product_id !== null], 'checked_at' => now(), 'error' => null]);

                return $s;
            }
            if ($product->discontinued) {
                $s->update(['status' => RangeSuggestion::DECLINED, 'error' => 'The supplier discontinued it before it was approved.', 'checked_at' => now()]);

                return $s;
            }
            if ($product->hydrated_at === null || ! $product->keepsFullDetail()) {
                $product = app(ProductHydrator::class)->hydrate($product->id) ?? throw new \RuntimeException('Promodata no longer has it.');
            } else {
                $product->forceFill(['last_used_at' => now()])->save(); // stays kept while it waits
            }
            $this->range->reprice($product);
            $result = $this->readiness->check($product->fresh());
            $s->update(['status' => $s->status === RangeSuggestion::PREPARING ? RangeSuggestion::PENDING : $s->status, 'ready' => $result['ready'],
                'checks' => $result, 'checked_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $s->update(['status' => $s->status === RangeSuggestion::PREPARING ? RangeSuggestion::PENDING : $s->status, 'ready' => false,
                'error' => mb_substr($e->getMessage(), 0, 500), 'checked_at' => now()]);
        }

        return $s;
    }

    /** Quotes from the last two months not yet ordered. */
    public function openQuotes()
    {
        return Quote::query()->whereIn('status', self::OPEN_STATUSES)
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subMonths(self::QUOTE_MONTHS))->orWhere('sent_at', '>=', now()->subMonths(self::QUOTE_MONTHS)));
    }

    /**
     * Open quotes per discontinued product.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, Collection<int, QuoteItem>> product id => items (with their quote)
     */
    public function quotesFor(array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return QuoteItem::query()->with('quote')->whereIn('product_id', $productIds)
            ->whereIn('quote_id', $this->openQuotes()->select('id'))->orderByDesc('id')->get()->groupBy('product_id');
    }

    /** Approve new products onto the range (queued, priced again as they are added). @param list<int> $ids suggestion ids */
    public function approve(array $ids, ?int $userId): int
    {
        $rows = RangeSuggestion::query()->whereKey($ids)->where('kind', RangeSuggestion::NEW)->where('status', RangeSuggestion::PENDING)->get();
        foreach ($rows->pluck('product_id')->chunk(25) as $chunk) {
            RangeBulkAction::dispatch('add', $chunk->values()->all(), $userId);
        }
        RangeSuggestion::query()->whereKey($rows->pluck('id'))->update(['status' => RangeSuggestion::APPROVED, 'decided_by' => $userId, 'decided_at' => now()]);

        return $rows->count();
    }

    /** Decline new products (never suggested again) or mark discontinued ones dealt with. @param list<int> $ids */
    public function close(array $ids, string $status, ?int $userId): int
    {
        return RangeSuggestion::query()->whereKey($ids)->where('status', RangeSuggestion::PENDING)
            ->update(['status' => $status, 'decided_by' => $userId, 'decided_at' => now()]);
    }

    /** Supplier setting on Admin › Add a whole supplier. @param list<string>|null $types */
    public static function watch(Supplier $supplier, bool $on, ?array $types): void
    {
        $supplier->forceFill([
            'suggest_new' => $on,
            'suggest_types' => $on ? ($types ? array_values($types) : null) : $supplier->suggest_types,
            'suggest_since' => $on ? ($supplier->suggest_new ? $supplier->suggest_since : now()) : $supplier->suggest_since,
        ])->save();
    }
}
