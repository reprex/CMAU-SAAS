<?php
declare(strict_types=1);

/**
 * Competitor price comparison (ADMIN LOGIN ONLY - staff and visitors get a 404).
 * - Latest comparison table with flags and implied markups over supplier cost
 * - Manual entry for competitor prices (sites without automatic extraction, quote-only sites)
 * - Manual entry for supplier cost / suggested markup (e.g. Trends distributor portal) - never scraped
 * - CSV export
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Cmau\Auth\Auth;
use Cmau\Competitor\{Comparison, Repository};

Auth::startSession();
Auth::requireAdmin();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$repo = new Repository(cmau_db());
$h = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$num = static fn(string $k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (float)$_POST[$k] : null;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Bad request');
    }
    if (($_POST['action'] ?? '') === 'competitor_price') {
        $comp = $repo->competitorByCode(trim($_POST['competitor'] ?? ''));
        if ($comp) {
            $matchId = $repo->addMatch((int)$comp['id'], trim($_POST['appaid']), trim($_POST['url']), 'manual',
                trim($_POST['supplier_code'] ?? '') ?: null, trim($_POST['title'] ?? '') ?: null,
                trim($_POST['decoration'] ?? '') ?: null);
            $incGst = isset($_POST['inc_gst']);
            $exGst = static fn(?float $v) => $v === null ? null : ($incGst ? round($v / 1.1, 4) : $v);
            $repo->addCompetitorSnapshot($matchId, (int)$_POST['qty'], $exGst($num('unit')), $exGst($num('setup')),
                $exGst($num('freight')), 'manual', $incGst, trim($_POST['note'] ?? '') ?: null);
            $msg = 'Competitor price saved.';
        } else {
            $msg = 'Unknown competitor code.';
        }
    } elseif (($_POST['action'] ?? '') === 'supplier_cost') {
        $repo->addSupplierCost([
            'supplier' => trim($_POST['supplier']), 'supplier_code' => trim($_POST['supplier_code']),
            'cmau_appaid' => trim($_POST['appaid'] ?? '') ?: null, 'qty' => (int)$_POST['qty'],
            'unbranded_cost' => $num('unbranded_cost'), 'decorated_unit_cost' => (float)$_POST['decorated_unit_cost'],
            'decoration_label' => trim($_POST['decoration'] ?? '') ?: null, 'setup_cost' => $num('setup_cost'),
            'suggested_markup_pct' => $num('markup_pct'), 'suggested_sell_unit' => $num('suggested_sell'),
        ]);
        $msg = 'Supplier cost saved.';
    }
}

$rows = $repo->comparisonRows();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="competitor-prices-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
    }
    exit;
}

$money = static fn($v) => $v === null ? '–' : '$' . number_format((float)$v, 2);
$pct = static fn($v) => $v === null ? '–' : number_format((float)$v * 100, 1) . '%';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Competitor prices</title>
<style>
 :root { --bg:#fff; --fg:#1d1d1f; --muted:#6b6b70; --line:#e3e3e6; --bad:#b42318; --good:#027a48; }
 @media (prefers-color-scheme: dark) { :root { --bg:#141416; --fg:#eee; --muted:#9a9aa0; --line:#2c2c30; --bad:#f97066; --good:#32d583; } }
 body { font: 14px/1.4 system-ui, sans-serif; background: var(--bg); color: var(--fg); margin: 0 16px 40px; }
 table { border-collapse: collapse; width: 100%; margin: 12px 0; } th, td { border-bottom: 1px solid var(--line); padding: 6px 8px; text-align: left; }
 td.n { text-align: right; font-variant-numeric: tabular-nums; } .dearer { color: var(--bad); } .much_cheaper { color: var(--good); }
 .muted { color: var(--muted); } form { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin: 8px 0 20px; }
 label { display: flex; flex-direction: column; font-size: 12px; color: var(--muted); } input { padding: 4px 6px; }
 .wrap { overflow-x: auto; }
</style>
</head>
<body>
<h1>Competitor prices</h1>
<p class="muted">All prices ex-GST. Diff = CMAU minus competitor on project total (positive = we are dearer).
Implied markup = price over supplier decorated unit cost. <a href="?export=csv">Export CSV</a></p>
<?php if ($msg): ?><p><strong><?= $h($msg) ?></strong></p><?php endif; ?>

<div class="wrap"><table>
<tr><th>Product</th><th>Competitor</th><th>Qty</th><th>Comp unit</th><th>Comp total</th><th>CMAU unit</th><th>CMAU total</th>
<th>Diff</th><th>Comp markup</th><th>CMAU markup</th><th>vs supplier RRP</th><th>Checked</th></tr>
<?php foreach ($rows as $r): $flag = Comparison::flag($r); ?>
<tr class="<?= $h($flag) ?>">
 <td><?= $h($r['cmau_appaid']) ?> <span class="muted"><?= $h($r['competitor_title']) ?></span></td>
 <td><a href="<?= $h($r['competitor_url']) ?>" rel="noopener" target="_blank"><?= $h($r['competitor']) ?></a></td>
 <td class="n"><?= $h($r['qty']) ?></td>
 <td class="n"><?= $money($r['comp_unit']) ?></td><td class="n"><?= $money($r['comp_total']) ?></td>
 <td class="n"><?= $money($r['cmau_unit']) ?></td><td class="n"><?= $money($r['cmau_total']) ?></td>
 <td class="n"><?= $pct($r['diff_pct']) ?></td>
 <td class="n"><?= $pct($r['comp_implied_markup_pct']) ?></td><td class="n"><?= $pct($r['cmau_implied_markup_pct']) ?></td>
 <td class="n"><?= $pct($r['comp_vs_suggested_pct']) ?></td>
 <td class="muted"><?= $h(substr((string)$r['captured_at'], 0, 10)) ?> · <?= $h($r['source']) ?></td>
</tr>
<?php endforeach; ?>
</table></div>

<h2>Add a competitor price</h2>
<form method="post">
 <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="competitor_price">
 <label>Competitor code<input name="competitor" required size="5"></label>
 <label>Our APPAID<input name="appaid" required size="8"></label>
 <label>Supplier code<input name="supplier_code" size="10"></label>
 <label>Competitor URL<input name="url" type="url" required size="30"></label>
 <label>Their title<input name="title" size="20"></label>
 <label>Decoration<input name="decoration" size="14"></label>
 <label>Qty<input name="qty" type="number" min="1" required size="5"></label>
 <label>Unit $<input name="unit" type="number" step="0.0001" size="7"></label>
 <label>Setup $<input name="setup" type="number" step="0.01" size="6"></label>
 <label>Freight $<input name="freight" type="number" step="0.01" size="6"></label>
 <label><span>Shown inc GST</span><input name="inc_gst" type="checkbox"></label>
 <label>Note<input name="note" size="16"></label>
 <button>Save</button>
</form>

<h2>Add supplier cost (distributor portal, confidential)</h2>
<form method="post">
 <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="supplier_cost">
 <label>Supplier<input name="supplier" required size="10" value="Trends"></label>
 <label>Supplier code<input name="supplier_code" required size="10"></label>
 <label>Our APPAID<input name="appaid" size="8"></label>
 <label>Qty<input name="qty" type="number" min="1" required size="5"></label>
 <label>Unbranded $<input name="unbranded_cost" type="number" step="0.0001" size="7"></label>
 <label>Decorated unit cost $<input name="decorated_unit_cost" type="number" step="0.0001" required size="7"></label>
 <label>Decoration<input name="decoration" size="14"></label>
 <label>Setup $<input name="setup_cost" type="number" step="0.01" size="6"></label>
 <label>Suggested markup %<input name="markup_pct" type="number" step="0.01" size="5"></label>
 <label>Suggested sell $<input name="suggested_sell" type="number" step="0.0001" size="7"></label>
 <button>Save</button>
</form>
</body>
</html>
