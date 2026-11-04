<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Quote;
use App\Models\WebsiteOrder;
use App\Support\Divisions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Divisions and the shared modules (Scott, 5 Nov 2026; docs/divisions.md): switching the view between
 * Replicat, CrowdMerch and CrowdLink, the shared business dashboard and management reports, and the
 * shared modules still to move in (contacts, freight).
 */
class DivisionController extends Controller
{
    /** Signed in: the home of the division being viewed, or the first one the user can open. */
    public function home(Request $request)
    {
        $keys = $request->user()->divisionKeys();
        $d = in_array(Divisions::viewing(), $keys, true) ? Divisions::viewing() : ($keys[0] ?? null);
        abort_if($d === null, 403, 'Your account has no access to any division yet. Ask an admin.');

        return redirect()->route(config("divisions.list.{$d}.home"));
    }

    public function switch(Request $request, string $division)
    {
        abort_unless(in_array($division, Divisions::keys(), true), 404);
        abort_unless($request->user()->access($division) !== null, 403, 'Your account has no access to '.Divisions::name($division).'.');
        session(['division' => $division]);

        return redirect()->route(config("divisions.list.{$division}.home"));
    }

    /** One business dashboard across the divisions the user can open. */
    public function dashboard(Request $request)
    {
        $keys = $request->user()->divisionKeys();
        $cards = [];
        foreach ($keys as $d) {
            $cards[$d] = Cache::remember("dashboard:{$d}", 300, fn () => $this->figures($d));
        }

        return view('shared.dashboard', ['cards' => $cards]);
    }

    public function reports()
    {
        return view('shared.reports', ['reports' => self::REPORTS]);
    }

    /** A module still to move in (Replicat and CrowdLink views, contacts, freight). */
    public function module(string $module)
    {
        return view('shared.module', ['module' => $module, 'info' => self::MODULES[$module]]);
    }

    /** @return array<string, mixed> */
    private function figures(string $division): array
    {
        if ($division !== 'crowdmerch') {
            return ['ready' => false];
        }
        $month = now()->startOfMonth();

        return ['ready' => true, 'rows' => [
            ['Quotes this month', Quote::query()->where('created_at', '>=', $month)->count()],
            ['Quotes sent this month', Quote::query()->where('sent_at', '>=', $month)->count()],
            ['Quotes won this month', Quote::query()->where('won_at', '>=', $month)->count()],
            ['Website orders this month', WebsiteOrder::query()->where('created_at', '>=', $month)->count()],
            ['Website orders this month, ex GST', '$'.number_format((float) WebsiteOrder::query()->where('created_at', '>=', $month)->sum('subtotal'), 2)],
            ['Products on the range', Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->count()],
        ]];
    }

    /** Management reports planned for the shared suite: every one can be filtered by division, or show all three. */
    public const REPORTS = [
        ['Sales by division', 'Quotes, orders and invoiced sales ex GST per division, by week, month and year, with last year alongside.'],
        ['Quote conversion', 'Quotes sent, won and lost per division and per staff member; average time from request to quote and quote to order.'],
        ['Top clients', 'Clients by sales across all three divisions (one contact list), with what each bought from each division.'],
        ['Margin', 'Cost, sell and margin per order and per product type; supplier and freight costs included.'],
        ['Freight', 'Consignments, carrier costs (Machship and FedEx) and freight charged, per division.'],
        ['Debtors', 'Invoices outstanding and overdue by client and division (payments read back from Xero).'],
        ['Production and despatch', 'Orders in production, due and late, per division (Replicat batches, CrowdMerch supplier orders).'],
        ['Website', 'CMAU searches, quote requests, orders and conversion; CrowdLink client store sales.'],
    ];

    /** @var array<string, array{title: string, eyebrow: string, what: string, from: string}> */
    public const MODULES = [
        'replicat' => ['title' => 'Replicat', 'eyebrow' => 'Replicat', 'what' => 'Quotes, orders, supplier orders (MPOL), production tracking, artwork and preflight, accounts and Replicat reporting, rebuilt from ROD.', 'from' => 'ROD.mdb stays in use until each Replicat module moves in here (REPSTACK: ROD To SaaS vet list).'],
        'crowdlink' => ['title' => 'CrowdLink', 'eyebrow' => 'CrowdLink', 'what' => 'Connected client stores, their sales and the weekly ARIA reports.', 'from' => 'CrowdLink runs on its own platform until it moves in here (REPSTACK: CrowdLink).'],
        'contacts' => ['title' => 'Contacts', 'eyebrow' => 'Shared', 'what' => 'One contact and company list for all three divisions; a client can buy from every division.', 'from' => 'ROD Contacts is the client master (REPSTACK principle 5); it moves in with the first Replicat module. Until then CrowdMerch keeps its quote clients.'],
        'freight' => ['title' => 'Freight', 'eyebrow' => 'Shared', 'what' => 'Machship (Australia) and FedEx (international) for every division: rates, booking, labels and tracking.', 'from' => 'The ROD Machship and FedEx tools move in as the shared freight module (REPSTACK: ROD To SaaS platform and freight design).'],
    ];
}
