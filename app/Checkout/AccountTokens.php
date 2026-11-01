<?php

namespace App\Checkout;

/**
 * My Orders account token: the client's email and an expiry, signed with the
 * app key (HMAC-SHA256). The theme keeps it in a cookie for 90 days and sends
 * it as "Authorization: Bearer <token>". It only ever unlocks that email's orders.
 */
class AccountTokens
{
    public function issue(string $email, ?int $now = null): string
    {
        $payload = $this->b64(json_encode(['e' => mb_strtolower(trim($email)), 'x' => ($now ?? time()) + 86400 * (int) config('website.account.token_days')], JSON_UNESCAPED_SLASHES));

        return $payload.'.'.$this->sign($payload);
    }

    /** The email the token was issued for, or null when it is forged, malformed or expired. */
    public function email(?string $token, ?int $now = null): ?string
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2 || ! hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ! is_string($data['e'] ?? null) || ! is_int($data['x'] ?? null) || $data['x'] < ($now ?? time())) {
            return null;
        }

        return $data['e'];
    }

    public function expiresAt(string $token): ?int
    {
        $data = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true);

        return is_array($data) ? ($data['x'] ?? null) : null;
    }

    private function sign(string $payload): string
    {
        return $this->b64(hash_hmac('sha256', 'website-account|'.$payload, (string) config('app.key'), true));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
