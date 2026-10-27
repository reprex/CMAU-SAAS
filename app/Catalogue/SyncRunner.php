<?php

namespace App\Catalogue;

use App\Models\SyncRun;
use App\Sources\SupplierSource;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs supplier-data syncs a few pages at a time, so each scheduled cron
 * run on shared hosting stays short. A run remembers its next page.
 */
class SyncRunner
{
    public function __construct(
        private readonly SupplierSource $source,
        private readonly CatalogueWriter $writer,
    ) {}

    public function start(string $kind, ?DateTimeInterface $since = null, ?int $supplierId = null): SyncRun
    {
        return SyncRun::query()->create([
            'source' => $this->source->name(),
            'kind' => $kind,
            'since' => $since,
            'supplier_id' => $supplierId,
            'status' => SyncRun::STATUS_PENDING,
        ]);
    }

    /**
     * Starts a "changed since" run from when the last successful run started,
     * unless one is already open.
     */
    public function startChangedSinceLastRun(): ?SyncRun
    {
        if (SyncRun::query()->open()->exists()) {
            return null;
        }

        $last = SyncRun::query()
            ->where('source', $this->source->name())
            ->where('status', SyncRun::STATUS_COMPLETED)
            ->latest('started_at')
            ->first();

        // With no previous run, start a full sync instead.
        if ($last === null) {
            return $this->start(SyncRun::KIND_FULL);
        }

        // Overlap by an hour so nothing changed during the last run is missed.
        return $this->start(SyncRun::KIND_CHANGED, $last->started_at->subHour());
    }

    /** Processes up to $maxPages pages of the oldest open run. Returns the run, or null if none. */
    public function step(int $maxPages): ?SyncRun
    {
        $run = SyncRun::query()->open()->oldest('id')->first();
        if ($run === null) {
            return null;
        }

        $run->forceFill([
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        try {
            for ($i = 0; $i < $maxPages; $i++) {
                $page = $this->source->fetchPage($run->next_page, $run->since, $run->supplier_id);

                $seen = 0;
                $changed = 0;
                foreach ($page->products as $product) {
                    $seen++;
                    $result = $this->writer->store($product);
                    if ($result !== CatalogueWriter::UNCHANGED) {
                        $changed++;
                    }
                }

                $run->forceFill([
                    'total_pages' => $page->totalPages,
                    'next_page' => $run->next_page + 1,
                    'products_seen' => $run->products_seen + $seen,
                    'products_changed' => $run->products_changed + $changed,
                ])->save();

                if ($page->isLast() || $page->products === []) {
                    $run->forceFill([
                        'status' => SyncRun::STATUS_COMPLETED,
                        'finished_at' => now(),
                    ])->save();
                    break;
                }
            }
        } catch (Throwable $e) {
            // Leave the run open so the next scheduled step retries this page;
            // fail it only after repeated errors on the same page.
            $attempts = (int) cache()->increment("sync-run:{$run->id}:page:{$run->next_page}:failures");
            Log::error('Supplier sync step failed', ['run' => $run->id, 'page' => $run->next_page, 'error' => $e->getMessage()]);

            $run->forceFill(['error' => $e->getMessage()]);
            if ($attempts >= 5) {
                $run->forceFill(['status' => SyncRun::STATUS_FAILED, 'finished_at' => now()]);
            }
            $run->save();
        }

        return $run->refresh();
    }
}
