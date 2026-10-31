<?php

namespace App\Catalogue;

use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Learn logo areas from a vetted sample and apply them to the whole range
 * (docs/logo-preview.md).
 *
 * 1. pickSample(): about 100 web-range products spread across suppliers and
 *    product types (bigger groups get more), for staff to vet.
 * 2. learn(): for each vetted sample, how far staff moved the prediction
 *    (left/top in percentage points, width as a ratio). The median correction
 *    of the closest group is applied to every unvetted product's prediction:
 *    same supplier and product type if it has 2+ vetted samples, else same
 *    product type, else all vetted samples. A group whose corrections disagree
 *    is listed for a few more samples.
 */
class LogoAreaLearner
{
    public const MIN_GROUP = 2;

    public const SPREAD_LIMIT = 6.0; // percentage points: above this a group needs more samples

    public function __construct(private readonly PrintAreas $areas) {}

    /** @return int how many products were added to the sample */
    public function pickSample(int $size = 100): int
    {
        $live = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereNotNull('hero_image_url')
            ->get(['id', 'supplier_id', 'promodata_type_name', 'name', 'hero_image_url']);
        $groups = $live->groupBy(fn ($p) => $p->supplier_id.'|'.mb_strtolower((string) $p->promodata_type_name))->sortByDesc(fn ($g) => $g->count());
        $already = PrintArea::query()->where(fn ($q) => $q->where('sample', true)->orWhereNotNull('approved_at'))->pluck('product_id')->flip();
        $total = max(1, $live->count());

        // Groups whose vetted samples disagree get 3 more first; then one per group
        // (largest first), with extra for big groups in proportion to their size.
        $picks = collect();
        $disagree = $this->learnSummary()->filter(fn ($g) => $g['spread'] > self::SPREAD_LIMIT)->pluck('key');
        foreach ($disagree as $key) {
            $picks = $picks->merge(($groups[$key] ?? collect())->reject(fn ($p) => $already->has($p->id))->shuffle()->take(3));
        }
        foreach ($groups as $g) {
            if ($picks->count() >= $size) {
                break;
            }
            $want = max(1, (int) floor($size * $g->count() / $total));
            $have = $g->filter(fn ($p) => $already->has($p->id))->count();
            $picks = $picks->merge($g->reject(fn ($p) => $already->has($p->id))->shuffle()->take(max(0, $want - $have)));
        }
        $added = 0;
        foreach ($picks->take($size) as $p) {
            $product = Product::query()->with(['images', 'colours', 'override'])->find($p->id);
            $area = $product ? $this->areas->ensure($product) : null;
            if ($area && ! $area->sample) {
                $area->forceFill(['sample' => true])->save();
                $added++;
            }
        }

        return $added;
    }

    /** @return array{groups: Collection, applied: int} */
    public function learn(): array
    {
        $vetted = PrintArea::query()->whereNotNull('approved_at')->whereNotNull('pred_left')->get();
        $delta = fn (PrintArea $a) => [
            'dl' => $a->left - $a->pred_left, 'dt' => $a->top - $a->pred_top,
            'rw' => $a->pred_width > 0 ? $a->width / $a->pred_width : 1.0,
        ];
        $byGroup = $vetted->groupBy('group_key')->map(fn ($g) => $g->map($delta));
        $byType = $vetted->groupBy(fn ($a) => explode('|', (string) $a->group_key, 2)[1] ?? '')->map(fn ($g) => $g->map($delta));
        $all = $vetted->map($delta);

        $applied = 0;
        $changed = [];
        PrintArea::query()->whereNull('approved_at')->whereNotNull('pred_left')
            ->chunkById(500, function ($rows) use ($byGroup, $byType, $all, &$applied, &$changed) {
                foreach ($rows as $a) {
                    $type = explode('|', (string) $a->group_key, 2)[1] ?? '';
                    [$from, $d] = match (true) {
                        ($byGroup[$a->group_key] ?? collect())->count() >= self::MIN_GROUP => ['group', $byGroup[$a->group_key]],
                        ($byType[$type] ?? collect())->count() >= self::MIN_GROUP => ['type', $byType[$type]],
                        $all->count() >= 10 => ['all', $all],
                        default => [null, null],
                    };
                    if ($from === null) {
                        continue;
                    }
                    $w = max(3, min(100, $a->pred_width * $this->median($d->pluck('rw'))));
                    $a->forceFill([
                        'left' => round(max(0, min(100 - $w, $a->pred_left + $this->median($d->pluck('dl')))), 2),
                        'top' => round(max(0, min(95, $a->pred_top + $this->median($d->pluck('dt')))), 2),
                        'width' => round($w, 2), 'source' => 'learned', 'learned_from' => $from,
                    ]);
                    if ($a->isDirty(['left', 'top', 'width'])) {
                        $changed[] = $a->product_id;
                    }
                    $a->save();
                    $applied++;
                }
            }, 'url_hash');
        // Changed areas on live products go to the website with the next publish.
        foreach (array_chunk(array_unique($changed), 500) as $ids) {
            Product::query()->whereIn('id', $ids)->where('tier', Product::TIER_LIVE)->update(['range_changed_at' => now()]);
        }

        $groups = $byGroup->map(fn ($d, $key) => [
            'key' => $key, 'samples' => $d->count(),
            'spread' => round(max($this->mad($d->pluck('dl')), $this->mad($d->pluck('dt'))), 1),
            'left' => round($this->median($d->pluck('dl')), 1), 'top' => round($this->median($d->pluck('dt')), 1), 'width' => round($this->median($d->pluck('rw')), 2),
        ])->values();

        return ['groups' => $groups, 'applied' => $applied];
    }

    /** The per-group corrections only (no changes): for the "What was learned" view. */
    public function learnSummary(): Collection
    {
        $vetted = PrintArea::query()->whereNotNull('approved_at')->whereNotNull('pred_left')->get();
        $counts = PrintArea::query()->selectRaw('group_key, count(*) as n')->groupBy('group_key')->pluck('n', 'group_key');

        return $vetted->groupBy('group_key')->map(function ($g, $key) use ($counts) {
            $dl = $g->map(fn ($a) => $a->left - $a->pred_left);
            $dt = $g->map(fn ($a) => $a->top - $a->pred_top);
            $rw = $g->map(fn ($a) => $a->pred_width > 0 ? $a->width / $a->pred_width : 1.0);
            $spread = round(max($this->mad($dl), $this->mad($dt)), 1);

            return ['key' => $key, 'samples' => $g->count(), 'products' => (int) ($counts[$key] ?? 0), 'left' => round($this->median($dl), 1),
                'top' => round($this->median($dt), 1), 'width' => round($this->median($rw), 2), 'spread' => $spread,
                'status' => $g->count() < self::MIN_GROUP ? 'one sample: uses the product type or all samples' : ($spread > self::SPREAD_LIMIT ? 'samples disagree: vet 2 or 3 more' : 'consistent')];
        })->sortByDesc('products')->values();
    }

    /** Groups (supplier and product type) whose vetted corrections disagree: vet a few more there. */
    public function inconsistent(Collection $groups): Collection
    {
        return $groups->filter(fn ($g) => $g['samples'] >= self::MIN_GROUP && $g['spread'] > self::SPREAD_LIMIT)->values();
    }

    private function median(Collection $v): float
    {
        $v = $v->sort()->values();
        $n = $v->count();

        return $n === 0 ? 0.0 : (float) ($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    }

    private function mad(Collection $v): float
    {
        $m = $this->median($v);

        return $this->median($v->map(fn ($x) => abs($x - $m)));
    }
}
