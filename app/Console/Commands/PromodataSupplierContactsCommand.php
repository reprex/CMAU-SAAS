<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Supplier;
use App\Sources\Promodata\PromodataClient;
use Illuminate\Console\Command;

/**
 * Orders contact and email for purchase orders, from Promodata's supplier list (Scott, 7 Nov 2026;
 * docs/purchase-orders.md). Shows every contact Promodata holds for the suppliers we sell, and the one
 * it would use (an orders / customer service contact first, else the supplier's main email). With
 * --apply it fills ONLY suppliers whose orders contact and email are both empty in the SaaS.
 */
class PromodataSupplierContactsCommand extends Command
{
    protected $signature = 'promodata:supplier-contacts {--apply : Fill empty orders contacts and emails}';

    protected $description = 'Suggest (and fill empty) supplier orders contacts and emails from Promodata';

    public function handle(PromodataClient $promodata): int
    {
        $list = [];
        $json = $promodata->get('/suppliers') ?? [];
        $page = 1;
        do {
            $rows = array_values(array_filter((array) ($json['data'] ?? $json), 'is_array'));
            foreach ($rows as $r) {
                $list[(int) ($r['id'] ?? 0)] = $r;
            }
            $more = $rows && ($json['next_page_url'] ?? $json['links']['next'] ?? (($json['meta']['current_page'] ?? $json['current_page'] ?? 1) < ($json['meta']['last_page'] ?? $json['last_page'] ?? 1)));
            $json = $more ? ($promodata->get('/suppliers', ['page' => ++$page]) ?? []) : [];
        } while ($more && $page <= 50);

        $ours = Supplier::query()->whereHas('products', fn ($q) => $q->where('tier', Product::TIER_LIVE))->orderBy('name')->get();
        $filled = 0;
        foreach ($ours as $s) {
            $r = $list[$s->id] ?? null;
            $this->line('');
            $this->info("{$s->id} {$s->name}".($s->orders_email ? "  (SaaS: {$s->orders_contact} {$s->orders_email})" : ''));
            if (! $r) {
                $this->line('  Not in Promodata\'s supplier list.');

                continue;
            }
            $contacts = array_values(array_filter((array) ($r['details']['contacts'] ?? []), fn ($c) => is_array($c) && filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL)));
            foreach ($contacts as $c) {
                $this->line(sprintf('  %-28s %-34s %s', mb_strimwidth((string) ($c['position'] ?? '-'), 0, 28, '…'), $c['email'], $c['name'] ?? ''));
            }
            $main = filter_var($r['details']['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $r['details']['email'] : null;
            if ($main) {
                $this->line(sprintf('  %-28s %s', 'Main email', $main));
            }
            usort($contacts, fn ($a, $b) => $this->rank((string) ($a['position'] ?? '')) <=> $this->rank((string) ($b['position'] ?? '')));
            $pick = $contacts && $this->rank((string) ($contacts[0]['position'] ?? '')) < 2 ? ['name' => $contacts[0]['name'] ?? null, 'email' => $contacts[0]['email']] : ($main ? ['name' => null, 'email' => $main] : null);
            if (! $pick) {
                continue;
            }
            $keep = filled($s->orders_email) || filled($s->orders_contact);
            $this->line('  → '.($keep ? 'kept SaaS value' : ($this->option('apply') ? 'filled: ' : 'would use: ').trim(($pick['name'] ?? '').' '.$pick['email'])));
            if (! $keep && $this->option('apply')) {
                $s->update(['orders_contact' => $pick['name'], 'orders_email' => $pick['email']]);
                $filled++;
            }
        }
        $this->line('');
        $this->line($this->option('apply') ? "Filled {$filled}. Edit any in Admin › Suppliers." : 'Nothing written; add --apply (st suppcontgo) to fill the empty ones.');

        return self::SUCCESS;
    }

    private function rank(string $position): int
    {
        return match (true) {
            (bool) preg_match('/order|customer\s*serv|\bcs\b|processing/i', $position) => 0,
            (bool) preg_match('/sales\s*(support|admin|coord)|account\s*manag|admin/i', $position) => 1,
            default => 2,
        };
    }
}
