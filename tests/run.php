<?php
declare(strict_types=1);

/** Dependency-free test runner: php tests/run.php */

require dirname(__DIR__) . '/src/bootstrap.php';

use Cmau\Competitor\{Comparison, DecorationJson, Fetcher, PriceCheckRunner, PriceExtractor, Repository, RobotsPolicy};

$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails, $count;
    $count++;
    if (!$ok) {
        $fails++;
        echo "FAIL $name $detail\n";
    }
}
function near(?float $a, ?float $b): bool
{
    return $a !== null && $b !== null && abs($a - $b) < 0.005;
}

// --- DecorationJson: real CMAU product 2029 (Budget Stubby Cooler), checked against the live PDP:
// qty 1 DigiFlex = 0.74 + 1.65 + $30 setup = $32.39 project total.
$dj2029 = '{"BrandFlag":"Price includes branding","UndecoratedPriceSetup":"0","UndecoratedPriceString":"1:0.74,500:0.73,1000:0.72,2500:0.69,5000:0.67,10000:0.66","Decorations":[{"DecID":"2029-digiflextransfer70mmx70mm","DecName":"DigiFlex Transfer (70mm x 70mm)","CombinedSetup":"30","CombinedPrcString":"1:1.65,500:1.65,1000:1.65,2500:1.65,5000:1.65,10000:1.65"},{"DecID":"2029-screenprintpercolourposition70mmx70mm","DecName":"Screen Print Per Colour/Position (70mm x 70mm)","CombinedSetup":"60","CombinedPrcString":"1:0.45,500:0.45,1000:0.45,2500:0.45,5000:0.45,10000:0.45"}]}';
$dj = new DecorationJson($dj2029);
$q = $dj->quote(1);
check('dj default decoration', $q['decoration'] === 'DigiFlex Transfer (70mm x 70mm)');
check('dj qty1 total matches PDP', near($q['total'], 32.39), json_encode($q));
$q = $dj->quote(250, 'Screen Print');
check('dj partial decoration name', str_starts_with($q['decoration'], 'Screen Print'));
check('dj 250 screen unit', near($q['unit'], 1.19));
check('dj 250 screen total', near($q['total'], 357.50));
// collectiondisplayprice on the live product is 1.43 at 250 = 357.50 / 250
check('dj matches collection display price', near($q['total'] / 250, 1.43));
$q = $dj->quote(500, 'Screen Print');
check('dj 500 uses 500 break', near($q['unit'], 1.18));
check('dj below MOQ break returns null', (new DecorationJson('{"UndecoratedPriceString":"100:1.74","Decorations":[{"DecName":"x","CombinedSetup":"60","CombinedPrcString":"100:0.45"}]}'))->quote(50) === null);
check('parseBreaks tolerates spaces and $', DecorationJson::parseBreaks(' 50:$1.20 , 100 : 1.10 ') === [50 => 1.2, 100 => 1.1]);

// --- RobotsPolicy: Brandconnect-style file (specific AI group with crawl-delay).
$robots = "User-agent: *\nDisallow: /search.php\nDisallow: /cart.php\n\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nCrawl-delay: 10\nDisallow: /cart.php\n";
$rp = new RobotsPolicy($robots);
$ua = 'Agent/CMAU-PriceCheck (+mailto:scott@replicat.com.au)';
check('robots product allowed', $rp->isAllowed('/budget-stubby-cooler/', $ua));
check('robots search blocked for *', !$rp->isAllowed('/search.php?q=x', $ua));
check('robots no delay for our agent', $rp->crawlDelay($ua) === null);
check('robots specific group', $rp->isAllowed('/search.php', 'ClaudeBot') && $rp->crawlDelay('ClaudeBot') === 10);
$rp2 = new RobotsPolicy("User-agent: *\nDisallow: /products.json\nDisallow: /collections/*/products.json\nAllow: /products/\n");
check('robots wildcard', !$rp2->isAllowed('/collections/all/products.json', $ua));
check('robots exact', !$rp2->isAllowed('/products.json?limit=250', $ua));
check('robots allow product page', $rp2->isAllowed('/products/2029', $ua));
check('robots longest match allow', (new RobotsPolicy("User-agent: *\nDisallow: /account\nAllow: /account/login\n"))->isAllowed('/account/login', $ua));
check('robots dollar anchor', (new RobotsPolicy("User-agent: *\nDisallow: /*.json$\n"))->isAllowed('/a.json?x=1', $ua));

// --- PriceExtractor
check('shopify json min variant', near(PriceExtractor::fromShopifyJson('{"product":{"variants":[{"price":"25.40"},{"price":"24.10"},{"price":"0.00"}]}}')[0]['unit'], 24.10));
// Promo Punks style bulk tag (real data: Sands Umbrella UM001, $24.59 base).
$b = PriceExtractor::fromShopifyJson('{"product":{"tags":"bulk_50:12-100:15-250:17-500:20, fastandquick","variants":[{"price":"24.59"}]}}');
check('shopify bulk tag tiers', near(PriceExtractor::unitAt($b, 25), 24.59) && near(PriceExtractor::unitAt($b, 100), 20.9015)
    && near(PriceExtractor::unitAt($b, 499), 20.4097) && near(PriceExtractor::unitAt($b, 500), 19.672), json_encode($b));
$html = '<script type="application/ld+json">{"@type":"Product","offers":{"@type":"Offer","price":"12.50","priceSpecification":[{"@type":"UnitPriceSpecification","price":"11.36","eligibleQuantity":{"minValue":100}},{"@type":"UnitPriceSpecification","price":"10.43","eligibleQuantity":{"minValue":250}}]}}</script>';
$b = PriceExtractor::fromJsonLd($html);
check('jsonld breaks', near(PriceExtractor::unitAt($b, 250), 10.43) && near(PriceExtractor::unitAt($b, 300), 10.43));
$table = '<table><tr><td>250</td><td>$1.57</td></tr><tr><td>500</td><td>$1.52</td></tr><tr><td>1,000</td><td>$1.44</td></tr></table>';
$b = PriceExtractor::fromRegex($table, '/(?<qty>\d[\d,]*)\s+\$(?<price>\d+\.\d{2})/');
check('regex breaks', count($b) === 3 && near(PriceExtractor::unitAt($b, 1000), 1.44), json_encode($b));

// --- Comparison
$r = Comparison::enrich(['comp_total' => 442.50, 'cmau_total' => 357.50, 'comp_unit' => 1.77, 'cmau_unit' => 1.19,
                         'supplier_unit_cost' => 0.90, 'suggested_sell_unit' => 1.50]);
check('diff total', near($r['diff_total'], -85.00));
check('diff pct', near($r['diff_pct'], -0.1921));
check('implied markup', near($r['comp_implied_markup_pct'], 0.9667));
check('flag much cheaper', Comparison::flag($r) === 'much_cheaper');

// --- Repository + runner against SQLite, with a fake transport (no network).
$pdo = new PDO('sqlite::memory:');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$repo = new Repository($pdo);
$pdo->exec("INSERT INTO competitors (code,name,base_url,platform,robots_allows_products,terms_prohibit_scraping,crawl_delay_seconds)
            VALUES ('PP','Promo Punks','https://pp.test','shopify',1,0,10),
                   ('XX','Blocked Terms','https://xx.test','custom',1,1,10),
                   ('RB','Robots Block','https://rb.test','custom',1,0,10)");
$m1 = $repo->addMatch(1, '2029', 'https://pp.test/products/stubby', 'name_image', '100580', 'Stubby', 'Screen Print');
$m2 = $repo->addMatch(2, '2029', 'https://xx.test/p/stubby');
$m3 = $repo->addMatch(3, '2029', 'https://rb.test/private/stubby');
$requests = [];
$sleeps = [];
$transport = function (string $url) use (&$requests): array {
    $requests[] = $url;
    return match (true) {
        str_ends_with($url, 'pp.test/robots.txt') => ['status' => 200, 'body' => "User-agent: *\nDisallow: /cart\n"],
        str_ends_with($url, 'rb.test/robots.txt') => ['status' => 200, 'body' => "User-agent: *\nDisallow: /private/\n"],
        str_ends_with($url, '/products/stubby.json') => ['status' => 200, 'body' => '{"product":{"variants":[{"price":"1.30"}]}}'],
        default => ['status' => 404, 'body' => ''],
    };
};
$fetcher = new Fetcher($repo, 'Agent/CMAU-PriceCheck (+mailto:test@example.com)', $transport, function (float $s) use (&$sleeps) { $sleeps[] = $s; });
$runner = new PriceCheckRunner($repo, $fetcher, fn(string $id) => $id === '2029' ? $dj2029 : null, [250, 500]);
$stats = $runner->run();
check('runner skips terms-prohibited competitor', $stats['skipped'] >= 1 && !in_array('https://xx.test/p/stubby', $requests, true));
check('runner never fetched robots-disallowed url', !in_array('https://rb.test/private/stubby', $requests, true), json_encode($requests));
check('runner priced shopify match', $stats['priced'] === 1, json_encode($stats));
$log = $pdo->query("SELECT outcome FROM competitor_fetch_log WHERE url = 'https://rb.test/private/stubby'")->fetchColumn();
check('robots block logged', $log === 'blocked_robots');
$rows = $repo->comparisonRows();
check('comparison has from-price row at top qty', count($rows) === 1 && (int)$rows[0]['qty'] === 500, json_encode($rows));
check('comparison joins our price', near((float)$rows[0]['cmau_unit'], 1.18) && near((float)$rows[0]['comp_unit'], 1.30));
$repo->addSupplierCost(['supplier' => 'Trends', 'supplier_code' => '100580', 'qty' => 500, 'decorated_unit_cost' => 0.95, 'suggested_sell_unit' => 1.40]);
$rows = $repo->comparisonRows();
check('supplier cost joined', near($rows[0]['cmau_implied_markup_pct'], 0.2421) && near($rows[0]['comp_vs_suggested_pct'], -0.0714), json_encode($rows[0]));

// Crawl delay is enforced between requests to the same host.
$requests = [];
$sleeps = [];
$f2 = new Fetcher($repo, 'Agent/T', $transport, function (float $s) use (&$sleeps) { $sleeps[] = $s; });
$comp = $repo->competitorByCode('PP');
$f2->get($comp, 'https://pp.test/products/stubby.json');
$f2->get($comp, 'https://pp.test/products/stubby.json');
check('crawl delay enforced', count($sleeps) >= 1 && max($sleeps) > 9, json_encode($sleeps));

// Admin gate: page source must call requireAdmin before any output or data access.
$src = file_get_contents(dirname(__DIR__) . '/public/competitor-prices.php');
check('page requires admin before data', strpos($src, 'Auth::requireAdmin()') !== false
    && strpos($src, 'Auth::requireAdmin()') < strpos($src, 'new Repository('));

echo $fails === 0 ? "OK ($count checks)\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
