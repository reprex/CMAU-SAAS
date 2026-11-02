<?php

namespace App\Jobs;

use App\Curation\CurationApplier;
use App\Models\CurationRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Applies an approved curation request and republishes the product (within the minute cron). */
class ApplyCurationRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $requestId) {}

    public function handle(CurationApplier $applier): void
    {
        $r = CurationRequest::find($this->requestId);
        if ($r && $r->status === 'approved') {
            $applier->apply($r);
        }
    }
}
