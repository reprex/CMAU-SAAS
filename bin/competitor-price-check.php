<?php
declare(strict_types=1);

/**
 * Competitor price check.
 *   php bin/competitor-price-check.php run      # collect prices from cleared competitors
 *   php bin/competitor-price-check.php export   # print the latest comparison as CSV
 *
 * Suitable for a cPanel cron job (no SSH needed), e.g. monthly:
 *   0 3 1 * * /usr/local/bin/php /home/ACCOUNT/cmau-saas/bin/competitor-price-check.php run
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Cmau\Competitor\{Fetcher, PriceCheckRunner, Repository};

$cmd = $argv[1] ?? 'run';
$db = cmau_db();
$repo = new Repository($db);
$cfg = require dirname(__DIR__) . '/config/config.php';

if ($cmd === 'run') {
    $fetcher = new Fetcher($repo, $cfg['competitor_prices']['user_agent']);
    // Our DecorationJSON comes from the SaaS product table the Shopify publisher maintains.
    $djFor = static function (string $appaid) use ($db): ?string {
        $st = $db->prepare('SELECT decoration_json FROM cmau_product_pricing WHERE appaid = ?');
        $st->execute([$appaid]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    };
    $stats = (new PriceCheckRunner($repo, $fetcher, $djFor))->run();
    printf("Checked %d, priced %d, skipped %d\n", $stats['checked'], $stats['priced'], $stats['skipped']);
    foreach ($stats['messages'] as $m) {
        echo " - $m\n";
    }
    exit(0);
}

if ($cmd === 'export') {
    $out = fopen('php://stdout', 'w');
    $rows = $repo->comparisonRows();
    if ($rows !== []) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
    }
    exit(0);
}

fwrite(STDERR, "Unknown command: $cmd (use run or export)\n");
exit(1);
