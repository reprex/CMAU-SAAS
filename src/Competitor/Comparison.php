<?php
declare(strict_types=1);

namespace Cmau\Competitor;

/** Derived comparison figures for one product/competitor/qty row. */
final class Comparison
{
    /**
     * Adds:
     *  - diff_total / diff_pct: CMAU minus competitor on project total (positive = we are dearer)
     *  - comp_implied_markup_pct: competitor unit price over supplier decorated unit cost (e.g. Trends "Buy (unit)")
     *  - cmau_implied_markup_pct: same for our price
     *  - comp_vs_suggested_pct: competitor unit vs supplier's suggested sell price (0 = follows the suggestion)
     */
    public static function enrich(array $r): array
    {
        $num = static fn($v) => $v === null || $v === '' ? null : (float)$v;
        $compTotal = $num($r['comp_total'] ?? null);
        $cmauTotal = $num($r['cmau_total'] ?? null);
        $compUnit = $num($r['comp_unit'] ?? null);
        $cmauUnit = $num($r['cmau_unit'] ?? null);
        $cost = $num($r['supplier_unit_cost'] ?? null);
        $suggested = $num($r['suggested_sell_unit'] ?? null);

        $r['diff_total'] = ($compTotal !== null && $cmauTotal !== null) ? round($cmauTotal - $compTotal, 2) : null;
        $r['diff_pct'] = ($r['diff_total'] !== null && $compTotal > 0) ? round($r['diff_total'] / $compTotal, 4) : null;
        $r['comp_implied_markup_pct'] = ($compUnit !== null && $cost > 0) ? round($compUnit / $cost - 1, 4) : null;
        $r['cmau_implied_markup_pct'] = ($cmauUnit !== null && $cost > 0) ? round($cmauUnit / $cost - 1, 4) : null;
        $r['comp_vs_suggested_pct'] = ($compUnit !== null && $suggested > 0) ? round($compUnit / $suggested - 1, 4) : null;
        return $r;
    }

    /** Flag rows for review: we are much dearer, or much cheaper (possible money left on the table). */
    public static function flag(array $r, float $dearerThreshold = 0.10, float $cheaperThreshold = 0.15): ?string
    {
        $d = $r['diff_pct'] ?? null;
        if ($d === null) {
            return null;
        }
        return match (true) {
            $d > $dearerThreshold => 'dearer',
            $d < -$cheaperThreshold => 'much_cheaper',
            default => null,
        };
    }
}
