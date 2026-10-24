<?php
declare(strict_types=1);

// Simple PSR-4 autoloader so the app runs on shared hosting without Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Cmau\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** PDO connection from config/config.php (not committed; copy config/config.example.php). */
function cmau_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfgFile = dirname(__DIR__) . '/config/config.php';
        if (!is_file($cfgFile)) {
            throw new RuntimeException('Missing config/config.php - copy config/config.example.php and fill it in.');
        }
        $cfg = require $cfgFile;
        $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
