<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Models\ShopifyDraftOrder;
use App\Quotes\LineTransfer;
use App\Quotes\QuoteCalculator;
use App\Quotes\QuoteNumbers;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteController extends Controller
{
    public const TABS = [
        'requests' => 'New requests',
        'open' => 'Open quotes',
        'won' => 'Won',
        'wishlists' => 'Wish lists',
        'all' => 'All',
    ];

    public function __construct(private readonly QuoteCalculator $calculator) {}

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'requests';
        $search = trim((string) $request->query('q'));

        $quotes = Quote::query()
            ->withCount('items')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('client_email', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('client_company', 'like', "%{$search}%")
                ->orWhere('shopify_draft_name', 'like', "%{$search}%")))
            ->when($search === '', fn ($q) => match ($tab) {
                'requests' => $q->where('status', 'NEW')->where('kind', 'quote'),
                'open' => $q->whereIn('status', ['PHONE', 'QUOTE']),
                'won' => $q->whereIn('status', ['ORDER', 'PROOF SENT', 'PROOF OK', 'SHIPPED', 'INVOICED', 'PAID']),
                'wishlists' => $q->where('kind', 'wishlist'),
                default => $q,
            })
            ->latest('id')
            ->paginate(40)->withQueryString();

        $counts = [
            'requests' => Quote::query()->where('status', 'NEW')->where('kind', 'quote')->count(),
            'open' => Quote::query()->whereIn('status', ['PHONE', 'QUOTE'])->count(),
            'anonymous_month' => ShopifyDraftOrder::query()->where('anonymous', true)->whereNull('quote_id')
                ->where('shopify_created_at', '>=', now()->subDays(30))->count(),
            'errors' => ShopifyDraftOrder::query()->whereNotNull('error')->count(),
        ];

        return view('quotes.index', compact('quotes', 'tab', 'search', 'counts'));
    }

    public function create()
    {
        return view('quotes.create');
    }

    public function store(Request $request, QuoteNumbers $numbers)
    {
        $data = array_filter($this->validateHeader($request), fn ($v) => $v !== null && $v !== '');
        $quote = Quote::create($data + [
            'number' => $numbers->next(),
            'status' => 'PHONE',
            'source' => 'manual',
            'created_by' => $request->user()->id,
            'assigned_to' => $request->user()->id,
            'valid_until' => $data['valid_until'] ?? now()->addDays((int) config('quotes.valid_days'))->toDateString(),
            'lead_time' => $data['lead_time'] ?? config('quotes.default_lead_time'),
            'edited_at' => now(),
        ]);
        AuditLog::record('quote.created', $quote);

        return redirect()->route('quotes.show', $quote);
    }

    public function show(Quote $quote)
    {
        $quote->load(['items.skus', 'extras.supplier', 'assignee']);

        return view('quotes.show', [
            'quote' => $quote,
            'targets' => app(LineTransfer::class)->targetsFor($quote),
            'statuses' => Quote::STATUSES,
        ]);
    }

    public function update(Request $request, Quote $quote)
    {
        $quote->update($this->validateHeader($request) + ['edited_at' => $quote->edited_at ?? now()]);
        $this->calculator->recalculate($quote);
        AuditLog::record('quote.updated', $quote, $quote->getChanges());

        return back()->with('status', 'Quote details saved.');
    }

    public function status(Request $request, Quote $quote)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Quote::STATUSES))],
            'kind' => ['nullable', Rule::in(['quote', 'wishlist'])],
        ]);
        $changes = ['status' => $data['status'], 'edited_at' => $quote->edited_at ?? now()];
        if ($data['status'] === 'QUOTE' && $quote->sent_at === null) {
            $changes['sent_at'] = now();
        }
        if ($data['status'] === 'ORDER' && $quote->won_at === null) {
            $changes['won_at'] = now();
        }
        if (! empty($data['kind'])) {
            $changes['kind'] = $data['kind'];
        }
        $quote->update($changes);
        AuditLog::record('quote.status', $quote, $changes);

        return back()->with('status', 'Status set to '.Quote::STATUSES[$data['status']].'.');
    }

    public function recalculate(Quote $quote)
    {
        $this->calculator->recalculate($quote);

        return back()->with('status', 'Freight and totals recalculated.');
    }

    public function revise(Quote $quote)
    {
        $quote->increment('revision');
        AuditLog::record('quote.revised', $quote, ['revision' => $quote->revision]);

        return back()->with('status', "Now revision {$quote->revision}.");
    }

    public function destroy(Quote $quote)
    {
        $quote->delete();
        AuditLog::record('quote.deleted', $quote);

        return redirect()->route('quotes.index')->with('status', "{$quote->number} deleted.");
    }

    private function validateHeader(Request $request): array
    {
        return $request->validate([
            'title' => 'nullable|string|max:190',
            'client_company' => 'nullable|string|max:190',
            'client_name' => 'nullable|string|max:190',
            'client_email' => 'nullable|email|max:190',
            'client_phone' => 'nullable|string|max:64',
            'rod_contact_id' => 'nullable|integer|min:1',
            'delivery_name' => 'nullable|string|max:190',
            'delivery_address1' => 'nullable|string|max:190',
            'delivery_address2' => 'nullable|string|max:190',
            'delivery_city' => 'nullable|string|max:190',
            'delivery_state' => 'nullable|string|max:32',
            'delivery_postcode' => 'nullable|string|max:16',
            'deadline' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'lead_time' => 'nullable|string|max:190',
            'internal_notes' => 'nullable|string|max:5000',
            'decoration_display' => ['nullable', Rule::in(['combined', 'separate'])],
        ]);
    }
}
