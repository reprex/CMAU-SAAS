<?php

namespace App\Console\Commands;

use App\Catalogue\BrandingTemplates;
use App\Models\BrandingTemplate;
use App\Models\Product;
use App\Support\ClaudeLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Branding templates (docs/branding-templates.md): each run looks for supplier-
 * pattern templates on a few web-range products and reads up to --limit new
 * templates with Claude. Scheduled every minute.
 */
class CatalogueReadTemplatesCommand extends Command
{
    protected $signature = 'catalogue:read-templates
        {--product= : One product}
        {--limit=2 : Templates to read this run}
        {--all : Every template not read yet, until done (or --seconds)}
        {--again : Read templates already read again, with the current reading rules}
        {--parallel=1 : Send this many to Claude at once}
        {--seconds=0 : Stop after this many seconds (0: no limit)}
        {--supplier= : Only this supplier\'s products}
        {--reread : Mark every template already read (or failed) to be read again by the minute cron, then stop}
        {--retry-failed : Mark only the failed templates to be read again by the minute cron, then stop}
        {--compare= : Read already-read templates again with this model (e.g. claude-haiku-5-5) and keep it beside the reading in use (--limit of them, newest first)}';

    protected $description = 'Find and read supplier branding templates';

    public function handle(BrandingTemplates $templates): int
    {
        if (! config('images.check.enabled') && ! $this->option('product') && ! $this->option('all')) {
            return self::SUCCESS;
        }
        @ini_set('memory_limit', '1024M'); // a template PDF goes to Claude whole (up to 20 MB, more as base64)
        if ($n = BrandingTemplates::releaseStuck()) {
            $this->warn("{$n} templates that stopped an earlier run were set aside as failed.");
        }
        if ($model = $this->option('compare')) {
            $todo = BrandingTemplate::query()->where('status', 'read')
                ->whereDoesntHave('comparisons', fn ($q) => $q->where('model', $model))
                ->whereHas('positions', fn ($q) => $q->whereNotNull('image_url'))
                ->latest('read_at')->limit((int) $this->option('limit'))->get();
            $r = $templates->compare($todo, (string) $model, max(1, (int) $this->option('parallel')));
            $this->info("Read {$r['done']} templates with {$model} to compare ({$r['failed']} failed). Compare them on Range › Template reads.");
            if (ClaudeLimit::paused()) {
                $this->warn('Stopped: Claude API limit ('.(ClaudeLimit::pause()['why'] ?? '').').');
            }

            return self::SUCCESS;
        }
        if ($this->option('retry-failed')) {
            $n = BrandingTemplate::query()->where('status', 'failed')->update(['status' => 'new']);
            $this->info("{$n} failed templates will be read again by the minute cron.");

            return self::SUCCESS;
        }
        if ($this->option('reread')) {
            $n = $this->query(['read', 'none', 'failed'], now()->addMinute())->update(['status' => 'new']);
            $this->info("{$n} templates will be read again by the minute cron (".(int) config('images.templates.per_day', 5000).' a day at most).');

            return self::SUCCESS;
        }
        if ($this->option('product') && ! $templates->canRead()) {
            $this->error(blank(config('search.anthropic.key'))
                ? 'No Claude key on the server: set ANTHROPIC_API_KEY in .env, then run config:cache.'
                : 'Today\'s limit for template reads (TEMPLATE_READS_PER_DAY) is used up.');

            return self::FAILURE;
        }
        if ($id = $this->option('product')) {
            $product = Product::query()->with('supplier')->findOrFail((int) $id);
            $templates->discover($product);
            foreach (BrandingTemplate::query()->where('product_id', $product->id)->get() as $t) {
                $this->info($t->url.': '.$templates->read($t).' positions');
            }

            return self::SUCCESS;
        }

        // Look for supplier-pattern templates, a few products a run (the cron's run only).
        if (! $this->option('all')) {
            $this->discoverSome($templates);
        }

        $started = microtime(true);
        $read = 0;
        $positions = 0;
        $limit = $this->option('all') ? PHP_INT_MAX : (int) $this->option('limit');
        $parallel = max(1, (int) $this->option('parallel'));
        $statuses = $this->option('again') ? ['new', 'read', 'none', 'failed'] : ['new'];
        $since = now(); // --again: a template read in this run isn't read twice
        $total = $this->query($statuses, $since)->count();
        if ($this->option('all')) {
            $this->info("{$total} templates to read…");
        }
        $seen = [];
        while ($read < $limit) {
            if (! $templates->canRead()) {
                $this->warn('Stopped: no Claude key, or today\'s limit (TEMPLATE_READS_PER_DAY) is reached.');
                break;
            }
            $todo = $this->query($statuses, $since)->whereNotIn('id', $seen)->orderBy('id')->limit(min($parallel * 4, $limit - $read))->get();
            array_push($seen, ...$todo->pluck('id')->all());
            if ($todo->isEmpty()) {
                break;
            }
            $got = $templates->readMany($todo, $parallel);
            $read += count($got);
            $positions += array_sum($got);
            if ($this->option('all') && $read % 50 < count($got)) {
                $this->line("{$read} of {$total}…");
            }
            if ((int) $this->option('seconds') > 0 && microtime(true) - $started > (int) $this->option('seconds')) {
                break;
            }
        }
        $left = $this->query($statuses, $since)->count();
        $this->info("Read {$read} templates ({$positions} positions).".($left && $this->option('all') ? " {$left} left: run it again." : ''));

        return self::SUCCESS;
    }

    private function discoverSome(BrandingTemplates $templates): void
    {
        $cursor = (int) Cache::get('templates:cursor', 0);
        $batch = Product::query()->with('supplier')->where('tier', Product::TIER_LIVE)->where('id', '>', $cursor)
            ->whereHas('supplier', fn ($q) => $q->whereNotNull('template_url_pattern'))->orderBy('id')->limit(5)->get();
        foreach ($batch as $p) {
            $templates->discover($p);
        }
        Cache::forever('templates:cursor', $batch->isEmpty() ? 0 : $batch->last()->id);
    }

    /** Templates of range products to read. */
    private function query(array $statuses, \DateTimeInterface $since)
    {
        return BrandingTemplate::query()->whereIn('status', $statuses)
            ->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)
                ->when($this->option('supplier'), fn ($q) => $q->where('supplier_id', (int) $this->option('supplier')))->select('id'))
            ->when($this->option('again'), fn ($q) => $q->where(fn ($w) => $w->whereNull('read_at')->orWhere('read_at', '<', $since)));
    }
}
