<?php
declare(strict_types=1);

namespace Cmau\Competitor;

/**
 * Runs a price check across all active matches for collectable competitors.
 * Records competitor prices at the comparison quantities, and our own prices from DecorationJSON.
 */
final class PriceCheckRunner
{
    /** Quantities compared on every product. */
    public const QTYS = [50, 100, 250, 500];

    /**
     * @param \Closure(string):?string $decorationJsonFor returns a product's DecorationJSON by APPAID (null if unknown)
     */
    public function __construct(
        private readonly Repository $repo,
        private readonly Fetcher $fetcher,
        private readonly \Closure $decorationJsonFor,
        private readonly array $qtys = self::QTYS,
    ) {}

    /** @return array{checked:int,priced:int,skipped:int,messages:list<string>} */
    public function run(): array
    {
        $stats = ['checked' => 0, 'priced' => 0, 'skipped' => 0, 'messages' => []];
        $collectable = [];
        foreach ($this->repo->collectableCompetitors() as $c) {
            $collectable[(int)$c['id']] = $c;
        }
        $ourDone = [];
        foreach ($this->repo->activeMatches() as $m) {
            $stats['checked']++;
            $comp = $collectable[(int)$m['competitor_id']] ?? null;
            if ($comp === null) {
                $stats['skipped']++;
                $stats['messages'][] = "{$m['competitor_code']} {$m['cmau_appaid']}: competitor not cleared for automatic checks (enter manually)";
                continue;
            }
            $breaks = $this->extract($comp, $m);
            if ($breaks === []) {
                $stats['skipped']++;
                $stats['messages'][] = "{$m['competitor_code']} {$m['cmau_appaid']}: no price found, needs manual entry";
                continue;
            }
            foreach ($this->qtys as $qty) {
                $unit = PriceExtractor::unitAt($breaks, $qty);
                $source = $breaks[0]['source'];
                if ($unit === null && count($breaks) === 1 && $breaks[0]['qty'] === null) {
                    // Only a "from" price is shown: record it against the highest qty, flagged in the note.
                    if ($qty !== max($this->qtys)) {
                        continue;
                    }
                    $unit = $breaks[0]['unit'];
                    $this->repo->addCompetitorSnapshot((int)$m['id'], $qty, $unit, null, null, $source, false, 'from price only');
                } elseif ($unit !== null) {
                    $this->repo->addCompetitorSnapshot((int)$m['id'], $qty, $unit, null, null, $source);
                }
            }
            $stats['priced']++;

            if (!isset($ourDone[$m['cmau_appaid']])) {
                $this->recordOurPrices($m['cmau_appaid'], $m['decoration_label'] ?? null);
                $ourDone[$m['cmau_appaid']] = true;
            }
        }
        return $stats;
    }

    private function extract(array $comp, array $m): array
    {
        $url = $m['competitor_url'];
        if ($comp['platform'] === 'shopify') {
            $jsonUrl = preg_replace('~(/products/[^/?#]+).*$~', '$1.json', $url) ?? $url;
            $res = $this->fetcher->get($comp, $jsonUrl);
            return ($res && $res['status'] === 200) ? PriceExtractor::fromShopifyJson($res['body']) : [];
        }
        $res = $this->fetcher->get($comp, $url);
        if (!$res || $res['status'] !== 200) {
            return [];
        }
        if (!empty($comp['price_regex'])) {
            $b = PriceExtractor::fromRegex($res['body'], $comp['price_regex']);
            if ($b !== []) {
                return $b;
            }
        }
        return PriceExtractor::fromJsonLd($res['body']);
    }

    private function recordOurPrices(string $appaid, ?string $decoration): void
    {
        $json = ($this->decorationJsonFor)($appaid);
        if ($json === null) {
            return;
        }
        $dj = new DecorationJson($json);
        foreach ($this->qtys as $qty) {
            $q = $dj->quote($qty, $decoration);
            if ($q !== null) {
                $this->repo->addCmauSnapshot($appaid, $qty, $q['unit'], $q['setup'], $q['decoration']);
            }
        }
    }
}
