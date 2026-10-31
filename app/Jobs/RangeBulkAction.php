<?php

namespace App\Jobs;

use App\Catalogue\RangeManager;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Adds, removes or refreshes a batch of products in the background. Each
 * product added from the summary tier needs one Promodata call, so batches
 * run from the queue (processed by the one-minute cron) rather than in the
 * request (stack principle 1).
 */
class RangeBulkAction implements ShouldQueue
{
    use Queueable;

    public const ACTIONS = ['add', 'remove', 'refresh', 'reprice'];

    public int $timeout = 300;

    /** @param  list<int>  $productIds */
    public function __construct(public readonly string $action, public readonly array $productIds, public readonly ?int $userId = null) {}

    public function handle(RangeManager $range): void
    {
        if ($this->userId && ($user = User::query()->find($this->userId))) {
            Auth::setUser($user); // so the audit log names who asked
        }

        $done = 0;
        $failed = [];
        foreach (Product::query()->whereKey($this->productIds)->get() as $product) {
            try {
                match ($this->action) {
                    'add' => $range->add($product),
                    'remove' => $range->remove($product),
                    'refresh' => $range->refresh($product),
                    'reprice' => $range->reprice($product),
                };
                $done++;
            } catch (Throwable $e) {
                $failed[$product->id] = mb_substr($e->getMessage(), 0, 200);
            }
        }

        AuditLog::record("range.bulk_{$this->action}", null, ['done' => $done, 'failed' => $failed, 'requested' => count($this->productIds)]);
    }
}
