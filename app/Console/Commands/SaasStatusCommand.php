<?php

namespace App\Console\Commands;

use App\Catalogue\PhotoBrandings;
use App\Models\BrandingTemplate;
use App\Models\PhotoBranding;
use Illuminate\Console\Command;

/**
 * One short look at the background work, for a phone (Scott, 5 Nov 2026): `sh saas-app/st` from cPanel's Terminal.
 * Read-only.
 */
class SaasStatusCommand extends Command
{
    protected $signature = 'saas:status';

    protected $description = 'Template reading and photo checks at a glance (read-only)';

    public function handle(): int
    {
        $t = BrandingTemplate::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $left = (int) ($t['new'] ?? 0);
        $this->info('Templates: '.$t->map(fn ($n, $s) => "{$s} ".number_format($n))->implode(' · '));
        $this->line($left > 0 ? '  About '.max(1, (int) ceil($left / 15)).' minutes to go (15 a minute).' : '  None waiting.');
        $since = BrandingTemplate::query()->where('status', 'read')->where('read_at', '>=', now()->subHour())->count();
        $this->line("  Read in the last hour: {$since}");
        foreach (BrandingTemplate::query()->where('status', 'failed')->latest('updated_at')->limit(5)->get(['product_id', 'message']) as $f) {
            $this->line("  Failed {$f->product_id}: ".mb_strimwidth((string) $f->message, 0, 90, '…'));
        }

        $p = PhotoBranding::query();
        $this->info('Photos checked: '.number_format((clone $p)->count())
            .' · with example branding '.number_format((clone $p)->where('branding', '!=', PhotoBrandings::NONE)->count())
            .' · not used for mockups '.number_format((clone $p)->where(fn ($q) => $q->whereNull('branding')->orWhere('branding', PhotoBrandings::LARGE)->orWhere('plain', false))->count()));

        return self::SUCCESS;
    }
}
