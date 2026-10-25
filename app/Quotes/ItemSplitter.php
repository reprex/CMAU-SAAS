<?php

namespace App\Quotes;

use App\Models\AuditLog;
use App\Models\QuoteItem;
use App\Stock\PromodataStock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The three stages of a quote item:
 *   combined  - one line as the draft order sent it (product + decoration in one price);
 *   separated - the unbranded product and its decoration as their own lines;
 *   sku       - the product split into supplier SKUs (colour x size x qty), picked
 *               from Promodata's live stock list or entered by hand.
 */
class ItemSplitter
{
    public function __construct(
        private readonly PromodataStock $stock,
        private readonly ItemPricer $pricer,
    ) {}

    public function separate(QuoteItem $item): void
    {
        if ($item->stage === 'combined') {
            $item->update(['stage' => 'separated']);
            AuditLog::record('quote_item.separated', $item);
        }
    }

    public function combine(QuoteItem $item): void
    {
        if ($item->stage === 'separated') {
            $item->update(['stage' => 'combined']);
            AuditLog::record('quote_item.combined', $item);
        }
    }

    /**
     * The SKU list for the item's product, current colour first.
     *
     * @return array{ok: bool, message: string|null, rows: list<array<string, mixed>>}
     */
    public function skuList(QuoteItem $item): array
    {
        if (! $item->product_id || $item->product_id >= 10_000_000) {
            return ['ok' => false, 'message' => 'Not a Promodata product, so there is no SKU list. Enter sizes by hand.', 'rows' => []];
        }

        $result = $this->stock->rows($item->product_id);
        $item->update(['stock_checked_at' => now()]);

        if ($item->colour) {
            $want = mb_strtolower($item->colour);
            usort($result['rows'], fn ($a, $b) => (mb_strtolower((string) $b['colour']) === $want) <=> (mb_strtolower((string) $a['colour']) === $want));
        }

        return $result;
    }

    /**
     * Save the size x qty split. Rows: [colour, size, quantity, supplier_sku, stock_on_hand, source].
     * Rows with no quantity are dropped. The item quantity becomes the total and
     * the item is re-priced at that quantity when the catalogue can price it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{quantity_changed: bool, repriced: bool}
     */
    public function saveSkus(QuoteItem $item, array $rows, string $mode): array
    {
        if (! in_array($mode, ['list', 'manual'], true)) {
            throw new InvalidArgumentException('Unknown SKU mode.');
        }

        return DB::transaction(function () use ($item, $rows, $mode) {
            $item->skus()->delete();
            $total = 0;
            $position = 0;

            foreach ($rows as $row) {
                $qty = (int) ($row['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $total += $qty;
                $item->skus()->create([
                    'position' => ++$position,
                    'colour' => $this->text($row['colour'] ?? null),
                    'size' => $this->text($row['size'] ?? null),
                    'quantity' => $qty,
                    'supplier_sku' => $this->text($row['supplier_sku'] ?? null),
                    'stock_on_hand' => isset($row['stock_on_hand']) && $row['stock_on_hand'] !== '' ? (int) $row['stock_on_hand'] : null,
                    'source' => $mode,
                ]);
            }

            $changed = $total > 0 && $total !== $item->quantity;
            $repriced = false;
            $item->sku_mode = $mode;
            $item->stage = $total > 0 ? 'sku' : ($item->stage === 'sku' ? 'separated' : $item->stage);
            if ($changed) {
                $item->quantity = $total;
                $repriced = $this->pricer->reprice($item);
            }
            $item->save();
            AuditLog::record('quote_item.sizes_saved', $item, ['total' => $total, 'mode' => $mode]);

            return ['quantity_changed' => $changed, 'repriced' => $repriced];
        });
    }

    private function text(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }
}
