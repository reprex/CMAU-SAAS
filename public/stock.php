<?php
declare(strict_types=1);

// Stock relay for the CMAU PDP "Check stock" button.
// GET/POST /stock.php?id=<product id>  ->  Promodata check-stock-levels JSON.
// The Promodata token lives in config/config.php (never in the theme).

require dirname(__DIR__) . '/src/bootstrap.php';

$cfgFile = dirname(__DIR__) . '/config/config.php';
$cfg = is_file($cfgFile) ? (require $cfgFile)['promodata_stock'] ?? null : null;
if (!is_array($cfg) || empty($cfg['token'])) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"Stock relay is not configured"}';
    exit;
}

$cfg['cache_dir'] ??= dirname(__DIR__) . '/var/stock';

$result = (new Cmau\Stock\StockRelay($cfg))->handle($_GET, $_SERVER);

http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
echo $result['body'];
