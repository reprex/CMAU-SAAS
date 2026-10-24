<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Cmau\Auth\Auth;

Auth::startSession();
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$error = '';
// Only allow redirects to local paths.
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/');
if (!str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/';
}

if (($_GET['logout'] ?? '') === '1') {
    Auth::logout();
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Session expired, please try again.';
    } else {
        // Basic throttling: one attempt per second per session, and lock after 10 failures.
        $_SESSION['fails'] ??= 0;
        if ($_SESSION['fails'] >= 10) {
            $error = 'Too many attempts. Try again later.';
        } elseif ((new Auth(cmau_db()))->attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) {
            $_SESSION['fails'] = 0;
            header('Location: ' . $next);
            exit;
        } else {
            $_SESSION['fails']++;
            sleep(1);
            $error = 'Email or password is incorrect.';
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CMAU SaaS login</title>
<style>
 :root{--bg:#fff;--fg:#1d1d1f;--line:#d0d0d5}
 @media (prefers-color-scheme: dark){:root{--bg:#141416;--fg:#eee;--line:#3a3a40}}
 body{font:15px system-ui,sans-serif;background:var(--bg);color:var(--fg);display:grid;place-items:center;min-height:100vh;margin:0 16px}
 form{display:grid;gap:10px;width:100%;max-width:320px} input,button{padding:8px;font:inherit;border:1px solid var(--line);border-radius:6px}
</style></head>
<body><form method="post">
 <h1>CMAU SaaS</h1>
 <?php if ($error): ?><p role="alert"><?= $h($error) ?></p><?php endif; ?>
 <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>"><input type="hidden" name="next" value="<?= $h($next) ?>">
 <label>Email <input name="email" type="email" autocomplete="username" required></label>
 <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
 <button>Log in</button>
</form></body></html>
