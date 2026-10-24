<?php
declare(strict_types=1);

namespace Cmau\Competitor;

/**
 * Minimal robots.txt evaluator: picks the most specific user-agent group,
 * applies longest-match Allow/Disallow with * and $ wildcards, and reads Crawl-delay.
 */
final class RobotsPolicy
{
    /** @var list<array{agents:list<string>,rules:list<array{allow:bool,path:string}>,delay:?int}> */
    private array $groups = [];

    public function __construct(string $robotsTxt)
    {
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if (!$lastWasAgent || $current === null) {
                    $this->groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = count($this->groups) - 1;
                }
                $this->groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }
            if ($field === 'allow' || $field === 'disallow') {
                if ($value === '' && $field === 'disallow') {
                    continue; // "Disallow:" with no path allows everything
                }
                $this->groups[$current]['rules'][] = ['allow' => $field === 'allow', 'path' => $value];
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $this->groups[$current]['delay'] = (int)ceil((float)$value);
            }
        }
    }

    /** Rules for our agent: groups naming our token win over "*"; several matching groups merge. */
    private function rulesFor(string $userAgentToken): array
    {
        $token = strtolower($userAgentToken);
        $specific = [];
        $star = [];
        foreach ($this->groups as $g) {
            foreach ($g['agents'] as $a) {
                if ($a === '*') {
                    $star[] = $g;
                } elseif ($a !== '' && str_contains($token, $a)) {
                    $specific[] = $g;
                }
            }
        }
        $use = $specific !== [] ? $specific : $star;
        $rules = [];
        $delay = null;
        foreach ($use as $g) {
            $rules = array_merge($rules, $g['rules']);
            if ($g['delay'] !== null) {
                $delay = max($delay ?? 0, $g['delay']);
            }
        }
        return ['rules' => $rules, 'delay' => $delay];
    }

    public function isAllowed(string $path, string $userAgentToken): bool
    {
        $best = null;
        $bestLen = -1;
        foreach ($this->rulesFor($userAgentToken)['rules'] as $r) {
            if (self::matches($r['path'], $path)) {
                $len = strlen($r['path']);
                // Longest match wins; on a tie, Allow wins.
                if ($len > $bestLen || ($len === $bestLen && $r['allow'])) {
                    $best = $r['allow'];
                    $bestLen = $len;
                }
            }
        }
        return $best ?? true;
    }

    public function crawlDelay(string $userAgentToken): ?int
    {
        return $this->rulesFor($userAgentToken)['delay'];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $p = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^' . str_replace('\*', '.*', preg_quote($p, '#')) . ($anchored ? '$' : '') . '#';
        return (bool)preg_match($regex, $path);
    }
}
