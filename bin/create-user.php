<?php
declare(strict_types=1);

/**
 * Create or reset a SaaS user (run from cPanel Terminal or a one-off cron).
 *   php bin/create-user.php scott@replicat.com.au admin
 * Prompts for the password so it never appears in shell history.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

[$_, $email, $role] = $argv + [null, null, 'staff'];
if (!$email || !in_array($role, ['admin', 'staff'], true)) {
    fwrite(STDERR, "Usage: php bin/create-user.php EMAIL admin|staff\n");
    exit(1);
}
fwrite(STDOUT, 'Password (min 12 chars): ');
system('stty -echo 2>/dev/null');
$password = trim((string)fgets(STDIN));
system('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}
$db = cmau_db();
$hash = password_hash($password, PASSWORD_DEFAULT);
$st = $db->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([strtolower($email)]);
if ($id = $st->fetchColumn()) {
    $db->prepare('UPDATE users SET password_hash = ?, role = ?, active = 1 WHERE id = ?')->execute([$hash, $role, $id]);
    echo "Updated $email ($role)\n";
} else {
    $db->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)')->execute([strtolower($email), $hash, $role]);
    echo "Created $email ($role)\n";
}
