<?php

namespace App\Console\Commands;

use App\Search\BriefParser;
use App\Search\ClaudePlanner;
use App\Search\KeywordSearch;
use App\Search\Occasions;
use Illuminate\Console\Command;

/** Try the website search from the terminal (no Claude, no logging): what it understood and what it returns. */
class SearchTryCommand extends Command
{
    protected $signature = 'search:try {text* : What a visitor might type} {--limit=24} {--ai : Ask Claude which subcategories suit it (uses the API key; one call)}';

    protected $description = 'Show what the website search understands and returns for a phrase (read-only)';

    public function handle(BriefParser $parser, KeywordSearch $search): int
    {
        $text = implode(' ', (array) $this->argument('text'));
        $b = $parser->parse($text);
        $this->info('Understood: '.collect([
            'qty' => $b['qty'], 'budget' => $b['budget'] ? ($b['budget_min'] ? '$'.$b['budget_min'].'–$'.$b['budget'] : ($b['under'] ? 'under $' : 'about $').$b['budget']) : null,
            'subcategories' => implode(', ', $b['tags']), 'occasion' => Occasions::heading($b['occasions']), 'other words' => implode(' ', $b['rest']),
        ])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '));
        if ($this->option('ai')) {
            $plan = app(ClaudePlanner::class)->plan($text, $b['tags']);
            $this->info('Claude: '.($plan['heading'] ?? '').' · '.json_encode($plan['understood']));
            foreach ($plan['groups'] as $g) {
                $this->line('  '.$g['tag'].' — '.$g['why']);
            }
            $u = $plan['understood'];
            $b['budget'] ??= is_numeric($u['budget_each'] ?? null) && $u['budget_each'] > 0 ? (float) $u['budget_each'] : null;
            $b['qty'] ??= is_numeric($u['qty'] ?? null) && $u['qty'] > 0 ? (int) $u['qty'] : null;
            $tags = array_column($plan['groups'], 'tag');
            $rows = $search->forTags($tags, $b, (int) $this->option('limit'), count($tags) === 1 ? 24 : (count($tags) <= 4 ? 5 : 3));
        } else {
            $rows = $search->search($b, (int) $this->option('limit'));
        }
        $this->table(['Product', 'Name', 'Subcategory', 'Each', 'Qty'], $rows->map(fn ($r) => [$r['id'], mb_strimwidth($r['name'], 0, 44, '…'), $r['group'] ?? '', $r['price'] !== null ? '$'.number_format($r['price'], 2) : '?', $r['qty']])->all());

        return self::SUCCESS;
    }
}
