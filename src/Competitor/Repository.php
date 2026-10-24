<?php
declare(strict_types=1);

namespace Cmau\Competitor;

use PDO;

/** Data access for the competitor price module. Portable SQL (MySQL in production, SQLite in tests). */
final class Repository
{
    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Competitors we may collect from automatically: active, robots checked, terms checked and not prohibiting. */
    public function collectableCompetitors(): array
    {
        return $this->db->query(
            'SELECT * FROM competitors
              WHERE active = 1 AND robots_allows_products = 1 AND terms_prohibit_scraping = 0
              ORDER BY priority, name'
        )->fetchAll();
    }

    public function competitorByCode(string $code): ?array
    {
        $st = $this->db->prepare('SELECT * FROM competitors WHERE code = ?');
        $st->execute([$code]);
        return $st->fetch() ?: null;
    }

    public function activeMatches(?int $competitorId = null): array
    {
        $sql = 'SELECT m.*, c.code AS competitor_code, c.platform, c.price_regex
                  FROM competitor_product_matches m JOIN competitors c ON c.id = m.competitor_id
                 WHERE m.active = 1' . ($competitorId ? ' AND m.competitor_id = ?' : '') . '
                 ORDER BY c.code, m.cmau_appaid';
        $st = $this->db->prepare($sql);
        $st->execute($competitorId ? [$competitorId] : []);
        return $st->fetchAll();
    }

    public function addMatch(int $competitorId, string $appaid, string $url, string $method = 'manual',
                             ?string $supplierCode = null, ?string $title = null, ?string $decoration = null): int
    {
        $st = $this->db->prepare(
            'INSERT INTO competitor_product_matches
                (competitor_id, cmau_appaid, supplier_code, competitor_url, competitor_title, match_method, decoration_label, active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $st->execute([$competitorId, $appaid, $supplierCode, $url, $title, $method, $decoration, self::now()]);
        return (int)$this->db->lastInsertId();
    }

    public function addCompetitorSnapshot(int $matchId, int $qty, ?float $unitExGst, ?float $setupExGst,
                                          ?float $freightExGst, string $source, bool $displayedIncGst = false,
                                          ?string $note = null, ?string $capturedAt = null): void
    {
        $total = $unitExGst === null ? null : round($qty * $unitExGst + ($setupExGst ?? 0) + ($freightExGst ?? 0), 2);
        $st = $this->db->prepare(
            'INSERT INTO competitor_price_snapshots
                (match_id, captured_at, qty, unit_price_ex_gst, setup_ex_gst, freight_ex_gst, project_total_ex_gst,
                 prices_included_gst, source, source_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$matchId, $capturedAt ?? self::now(), $qty, $unitExGst, $setupExGst, $freightExGst, $total,
                      $displayedIncGst ? 1 : 0, $source, $note]);
    }

    public function addCmauSnapshot(string $appaid, int $qty, float $unit, float $setup, ?string $decoration,
                                    ?string $capturedAt = null): void
    {
        $st = $this->db->prepare(
            'INSERT INTO cmau_price_snapshots
                (cmau_appaid, captured_at, qty, decoration_label, unit_price_ex_gst, setup_ex_gst, project_total_ex_gst)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$appaid, $capturedAt ?? self::now(), $qty, $decoration, $unit, $setup, round($qty * $unit + $setup, 2)]);
    }

    public function addSupplierCost(array $row): void
    {
        $cols = ['supplier', 'supplier_code', 'cmau_appaid', 'captured_at', 'qty', 'unbranded_cost',
                 'decorated_unit_cost', 'decoration_label', 'setup_cost', 'suggested_markup_pct',
                 'suggested_setup_markup_pct', 'suggested_sell_unit', 'freight_cost'];
        $row['captured_at'] ??= self::now();
        $vals = array_map(fn($c) => $row[$c] ?? null, $cols);
        $st = $this->db->prepare('INSERT INTO supplier_cost_snapshots (' . implode(',', $cols) . ') VALUES ('
                                 . rtrim(str_repeat('?,', count($cols)), ',') . ')');
        $st->execute($vals);
    }

    public function logFetch(int $competitorId, string $url, ?int $status, string $outcome): void
    {
        $st = $this->db->prepare(
            'INSERT INTO competitor_fetch_log (competitor_id, url, http_status, fetched_at, outcome) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$competitorId, $url, $status, self::now(), $outcome]);
    }

    /**
     * Latest competitor price per match and qty, joined to our latest price and supplier cost at the same qty.
     * @return list<array<string,mixed>>
     */
    public function comparisonRows(): array
    {
        $sql = "
        SELECT c.code AS competitor, c.name AS competitor_name, m.cmau_appaid, m.supplier_code, m.competitor_title,
               m.competitor_url, m.decoration_label, s.qty, s.unit_price_ex_gst AS comp_unit, s.setup_ex_gst AS comp_setup,
               s.freight_ex_gst AS comp_freight, s.project_total_ex_gst AS comp_total, s.source, s.captured_at,
               cm.unit_price_ex_gst AS cmau_unit, cm.setup_ex_gst AS cmau_setup, cm.project_total_ex_gst AS cmau_total,
               sc.decorated_unit_cost AS supplier_unit_cost, sc.suggested_sell_unit
          FROM competitor_price_snapshots s
          JOIN competitor_product_matches m ON m.id = s.match_id
          JOIN competitors c ON c.id = m.competitor_id
          LEFT JOIN cmau_price_snapshots cm
                 ON cm.cmau_appaid = m.cmau_appaid AND cm.qty = s.qty
                AND cm.captured_at = (SELECT MAX(x.captured_at) FROM cmau_price_snapshots x
                                       WHERE x.cmau_appaid = m.cmau_appaid AND x.qty = s.qty)
          LEFT JOIN supplier_cost_snapshots sc
                 ON sc.supplier_code = m.supplier_code AND sc.qty = s.qty
                AND sc.captured_at = (SELECT MAX(y.captured_at) FROM supplier_cost_snapshots y
                                       WHERE y.supplier_code = m.supplier_code AND y.qty = s.qty)
         WHERE s.captured_at = (SELECT MAX(z.captured_at) FROM competitor_price_snapshots z
                                 WHERE z.match_id = s.match_id AND z.qty = s.qty)
         ORDER BY m.cmau_appaid, s.qty, c.code";
        return array_map([Comparison::class, 'enrich'], $this->db->query($sql)->fetchAll());
    }
}
