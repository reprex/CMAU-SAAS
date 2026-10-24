<?php
declare(strict_types=1);

namespace Cmau\Auth;

use PDO;

/**
 * Session login for the SaaS. Passwords use password_hash(); sessions are cookie-only,
 * HttpOnly, Secure and SameSite=Strict, and the id is regenerated on login.
 */
final class Auth
{
    public function __construct(private readonly PDO $db) {}

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    public function attempt(string $email, string $password): bool
    {
        $st = $this->db->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ? AND active = 1');
        $st->execute([strtolower(trim($email))]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        // Verify against a dummy hash when the user is unknown, so timing doesn't reveal valid emails.
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltuJ0x0uS0aZmKXk0yQ2l5r9C6hZ8vK';
        if (!password_verify($password, $hash) || !$u) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id'], 'email' => $u['email'], 'role' => $u['role']];
        $this->db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $u['id']]);
        return true;
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    /** Stop the request unless an admin is logged in. Non-admins get a plain 404 so the page isn't advertised. */
    public static function requireAdmin(string $loginUrl = '/login.php'): void
    {
        if (self::user() === null) {
            header('Location: ' . $loginUrl . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
        if (!self::isAdmin()) {
            http_response_code(404);
            exit('Not found');
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
