<?php
declare(strict_types=1);

namespace Cmau\Competitor;

/**
 * Polite HTTP fetcher for competitor pages.
 * - Identifies itself honestly (Agent/<name> + contact), never pretends to be a browser.
 * - Refuses competitors whose terms prohibit scraping or whose robots.txt has not been checked.
 * - Obeys robots.txt for every URL and waits at least the crawl delay between requests per host.
 */
final class Fetcher
{
    public const DEFAULT_DELAY = 10;

    /** @var array<string,RobotsPolicy> */
    private array $robots = [];
    /** @var array<string,float> */
    private array $lastRequestAt = [];

    /** @param \Closure(string):array{status:int,body:string}|null $transport injectable for tests */
    public function __construct(
        private readonly Repository $repo,
        private readonly string $userAgent = 'Agent/CMAU-PriceCheck (+mailto:scott@replicat.com.au)',
        private ?\Closure $transport = null,
        private ?\Closure $sleeper = null,
    ) {
        $this->transport ??= self::curlTransport($this->userAgent);
        $this->sleeper ??= static fn(float $s) => usleep((int)($s * 1_000_000));
    }

    /** @return array{status:int,body:string}|null null when blocked by policy */
    public function get(array $competitor, string $url): ?array
    {
        if ((int)($competitor['terms_prohibit_scraping'] ?? 1) !== 0) {
            $this->repo->logFetch((int)$competitor['id'], $url, null, 'blocked_terms');
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $policy = $this->robotsFor($competitor, $parts['scheme'] ?? 'https', $host);
        if (!$policy->isAllowed($path, $this->userAgent)) {
            $this->repo->logFetch((int)$competitor['id'], $url, null, 'blocked_robots');
            return null;
        }
        $delay = max(
            (int)($competitor['crawl_delay_seconds'] ?? self::DEFAULT_DELAY),
            $policy->crawlDelay($this->userAgent) ?? 0,
            self::DEFAULT_DELAY,
        );
        $this->wait($host, $delay);
        $res = ($this->transport)($url);
        $this->lastRequestAt[$host] = microtime(true);
        $this->repo->logFetch((int)$competitor['id'], $url, $res['status'], $res['status'] === 200 ? 'ok' : 'http_error');
        return $res;
    }

    private function robotsFor(array $competitor, string $scheme, string $host): RobotsPolicy
    {
        if (!isset($this->robots[$host])) {
            $res = ($this->transport)("$scheme://$host/robots.txt");
            $this->lastRequestAt[$host] = microtime(true);
            // A missing robots.txt (404) means no restrictions; any other failure means "don't crawl".
            $body = match (true) {
                $res['status'] === 200 => $res['body'],
                $res['status'] === 404 => '',
                default => "User-agent: *\nDisallow: /",
            };
            $this->robots[$host] = new RobotsPolicy($body);
        }
        return $this->robots[$host];
    }

    private function wait(string $host, int $delaySeconds): void
    {
        if (!isset($this->lastRequestAt[$host])) {
            return;
        }
        $elapsed = microtime(true) - $this->lastRequestAt[$host];
        if ($elapsed < $delaySeconds) {
            ($this->sleeper)($delaySeconds - $elapsed);
        }
    }

    private static function curlTransport(string $userAgent): \Closure
    {
        return static function (string $url) use ($userAgent): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json'],
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        };
    }
}
