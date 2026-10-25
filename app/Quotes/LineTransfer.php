<?php

namespace App\Quotes;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Models\QuoteExtra;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves or copies a quote line (a product item with its sizes, or an extra
 * line) to a new quote for the same client, or moves it to another quote with
 * the same client email. Automatic freight lines are never moved: both quotes
 * recalculate their own freight afterwards.
 */
class LineTransfer
{
    public function __construct(
        private readonly QuoteNumbers $numbers,
        private readonly QuoteCalculator $calculator,
    ) {}

    /** Other quotes this line may move to: same client email, not deleted. */
    public function targetsFor(Quote $source): Collection
    {
        if (! $source->client_email) {
            return new Collection;
        }

        return Quote::query()
            ->whereRaw('LOWER(client_email) = ?', [mb_strtolower($source->client_email)])
            ->whereKeyNot($source->id)
            ->latest('id')
            ->limit(30)
            ->get(['id', 'number', 'title', 'status', 'created_at']);
    }

    public function moveToNew(QuoteItem|QuoteExtra $line, ?int $userId = null): Quote
    {
        return DB::transaction(function () use ($line, $userId) {
            $source = $line->quote;
            $target = $this->newQuoteLike($source, $userId);
            $this->place($line, $target);
            $this->finish($source, $target, 'quote_line.moved_to_new', $line);

            return $target;
        });
    }

    public function copyToNew(QuoteItem|QuoteExtra $line, ?int $userId = null): Quote
    {
        return DB::transaction(function () use ($line, $userId) {
            $source = $line->quote;
            $target = $this->newQuoteLike($source, $userId);
            $this->duplicate($line, $target);
            $this->finish($source, $target, 'quote_line.copied_to_new', $line);

            return $target;
        });
    }

    public function moveToExisting(QuoteItem|QuoteExtra $line, Quote $target): Quote
    {
        $source = $line->quote;
        if ($target->is($source)) {
            throw new InvalidArgumentException('The line is already on that quote.');
        }
        if (! $source->client_email || mb_strtolower((string) $target->client_email) !== mb_strtolower($source->client_email)) {
            throw new InvalidArgumentException('Lines can only move to a quote for the same client email.');
        }

        return DB::transaction(function () use ($line, $source, $target) {
            $this->place($line, $target);
            $this->finish($source, $target, 'quote_line.moved_to_existing', $line);

            return $target;
        });
    }

    private function assertMovable(QuoteItem|QuoteExtra $line): void
    {
        if ($line instanceof QuoteExtra && $line->auto) {
            throw new InvalidArgumentException('Automatic freight lines stay with their quote; freight is worked out again on both quotes.');
        }
    }

    private function place(QuoteItem|QuoteExtra $line, Quote $target): void
    {
        $this->assertMovable($line);
        $line->forceFill(['quote_id' => $target->id, 'position' => $target->nextPosition()])->save();
    }

    private function duplicate(QuoteItem|QuoteExtra $line, Quote $target): void
    {
        $this->assertMovable($line);
        $copy = $line->replicate();
        $copy->forceFill(['quote_id' => $target->id, 'position' => $target->nextPosition()])->save();

        if ($line instanceof QuoteItem) {
            foreach ($line->skus as $sku) {
                $copy->skus()->create($sku->replicate(['quote_item_id'])->getAttributes());
            }
        }
    }

    /** A new quote for the same client and delivery address. */
    private function newQuoteLike(Quote $source, ?int $userId): Quote
    {
        $copy = $source->only([
            'kind', 'title', 'rod_contact_id', 'client_company', 'client_name', 'client_email', 'client_phone',
            'delivery_name', 'delivery_address1', 'delivery_address2', 'delivery_city', 'delivery_state',
            'delivery_postcode', 'delivery_country', 'urgency', 'deadline', 'lead_time', 'decoration_display',
        ]);

        return Quote::create($copy + [
            'number' => $this->numbers->next(),
            'status' => $source->kind === 'wishlist' ? 'SAVELIST' : 'PHONE',
            'source' => 'manual',
            'internal_notes' => 'Started from a line of '.$source->number.'.',
            'valid_until' => now()->addDays((int) config('quotes.valid_days'))->toDateString(),
            'created_by' => $userId,
            'assigned_to' => $userId,
            'edited_at' => now(),
        ]);
    }

    private function finish(Quote $source, Quote $target, string $action, QuoteItem|QuoteExtra $line): void
    {
        foreach ([$source, $target] as $quote) {
            $quote->forceFill(['edited_at' => $quote->edited_at ?? now()])->save();
            $this->calculator->recalculate($quote->fresh());
        }
        AuditLog::record($action, $line, ['from' => $source->number, 'to' => $target->number]);
    }
}
