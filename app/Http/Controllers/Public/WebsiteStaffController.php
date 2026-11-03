<?php

namespace App\Http\Controllers\Public;

use App\Curation\CurationApplier;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\GuardsWebsiteOrders;
use App\Jobs\ApplyCurationRequest;
use App\Models\AuditLog;
use App\Models\CurationRequest;
use App\Models\PositionRule;
use App\Models\Product;
use App\Models\Quote;
use App\Staff\StaffClients;
use App\Staff\StaffPasses;
use App\Staff\StaffQuotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * crowdmerch/staff/*: the website's staff tools (docs/website-staff.md). Every call carries a
 * staff pass (Authorization: Bearer) from /website-staff/connect.
 */
class WebsiteStaffController extends Controller
{
    use GuardsWebsiteOrders;

    public function __construct(private readonly StaffPasses $passes) {}

    private function pass(Request $request, ?string $scope = null): array
    {
        if ($blocked = $this->guard($request, 'staff', (int) config('website.staff.per_hour'))) {
            return [null, $blocked];
        }
        $pass = $this->passes->resolve($request, $scope);
        if (! $pass) {
            return [null, $this->reply($request, ['success' => false, 'message' => 'Your staff pass has expired. Please sign in again.', 'signed_out' => true], 401)];
        }

        return [$pass, null];
    }

    public function me(Request $request): JsonResponse
    {
        [$pass, $no] = $this->pass($request);
        if ($no) {
            return $no;
        }

        return $this->reply($request, ['success' => true, 'name' => $pass->user->name, 'role' => $pass->user->role, 'scopes' => $pass->scopes,
            'can_apply' => $this->passes->canApply($pass->user, $request), 'expires_at' => $pass->expires_at->toIso8601String()]);
    }

    public function signout(Request $request): JsonResponse
    {
        [$pass, $no] = $this->pass($request);
        if ($no) {
            return $this->reply($request, ['success' => true]);
        }
        $this->passes->revoke($pass);

        return $this->reply($request, ['success' => true]);
    }

    public function curation(Request $request, int $product, CurationApplier $applier): JsonResponse
    {
        [$pass, $no] = $this->pass($request, 'curate');
        if ($no) {
            return $no;
        }
        $p = Product::query()->find($product);
        if (! $p) {
            return $this->reply($request, ['success' => false, 'message' => 'That product is not in the SaaS.'], 404);
        }

        return $this->reply($request, ['success' => true, 'can_apply' => $this->passes->canApply($pass->user, $request)] + $applier->state($p));
    }

    public function requestCuration(Request $request, int $product): JsonResponse
    {
        [$pass, $no] = $this->pass($request, 'curate');
        if ($no) {
            return $no;
        }
        $p = Product::query()->with(['images', 'colours'])->find($product);
        if (! $p) {
            return $this->reply($request, ['success' => false, 'message' => 'That product is not in the SaaS.'], 404);
        }
        $imageUrls = $p->images->pluck('url')->push($p->hero_image_url)->filter()->unique()->values()->all();
        $colours = $p->colours->pluck('name')->unique()->values()->all();
        $v = Validator::make($request->all(), [
            'changes' => 'present|array',
            'changes.title' => 'nullable|string|max:255',
            'changes.description' => 'nullable|string|max:20000',
            'changes.hero_image_url' => ['nullable', Rule::in($imageUrls)],
            'changes.hidden_colours' => 'nullable|array',
            'changes.hidden_colours.*' => [Rule::in($colours)],
            'changes.collection_moq' => 'nullable|integer|min:1|max:1000000',
            'changes.actual_moq' => 'nullable|integer|min:1|max:1000000',
            'changes.positions' => 'nullable|array|max:12',
            'changes.positions.*.name' => 'required|string|max:60',
            'changes.positions.*.view' => ['required', Rule::in(PositionRule::VIEWS)],
            'changes.positions.*.width_mm' => 'nullable|integer|min:5|max:2000',
            'changes.positions.*.height_mm' => 'nullable|integer|min:5|max:2000',
            'changes.on_website' => 'nullable|boolean',
            'note' => 'nullable|string|max:2000',
            'page' => 'nullable|string|max:300',
        ]);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check the changes.', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $changes = array_intersect_key((array) $request->input('changes', []), CurationRequest::LABELS);
        if (array_key_exists('on_website', $changes) && $changes['on_website'] === null) {
            unset($changes['on_website']);
        }
        if (isset($changes['positions'])) {
            $changes['positions'] = array_map(fn ($r) => ['name' => trim($r['name']), 'view' => $r['view'], 'width_mm' => isset($r['width_mm']) ? (int) $r['width_mm'] : null, 'height_mm' => isset($r['height_mm']) ? (int) $r['height_mm'] : null], array_values($changes['positions']));
        }
        $note = trim((string) ($data['note'] ?? ''));
        if ($changes === [] && $note === '') {
            return $this->reply($request, ['success' => false, 'message' => 'Nothing to change yet.'], 422);
        }
        $apply = $changes !== [] && $this->passes->canApply($pass->user, $request);
        $before = array_intersect_key(($p->override?->data ?? []) + ['on_website' => $p->tier === Product::TIER_LIVE], $changes);
        $r = CurationRequest::query()->create([
            'product_id' => $p->id,
            'user_id' => $pass->user_id,
            'edits' => $changes,
            'before' => $before,
            'note' => $note ?: null,
            // An admin's changes apply straight away; a note on its own stays as a to-do.
            'status' => $apply ? 'approved' : 'pending',
            'decided_by' => $apply ? $pass->user_id : null,
            'decided_at' => $apply ? now() : null,
            'source_url' => $data['page'] ?? null,
        ]);
        AuditLog::record('curation.requested', $p, ['request' => $r->id, 'status' => $r->status]);
        if ($apply) {
            ApplyCurationRequest::dispatch($r->id);
        }

        return $this->reply($request, [
            'success' => true,
            'id' => $r->id,
            'status' => $r->status,
            'message' => $apply ? 'Saved. The product updates on the website within a minute or two.' : 'Sent for approval. You\'ll see it under Curation requests in the SaaS.',
        ]);
    }

    // ---- Working for a client (cart page) -------------------------------------------------

    public function clients(Request $request, StaffClients $clients): JsonResponse
    {
        [$pass, $no] = $this->pass($request, 'assist');
        if ($no) {
            return $no;
        }

        return $this->reply($request, ['success' => true, 'clients' => $clients->search((string) $request->query('q', ''))]);
    }

    public function clientQuotes(Request $request, StaffClients $clients): JsonResponse
    {
        [$pass, $no] = $this->pass($request, 'assist');
        if ($no) {
            return $no;
        }
        $email = (string) $request->query('email', '');

        return $this->reply($request, ['success' => true, 'quotes' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $clients->openQuotes($email) : []]);
    }

    /** Multipart: cart (JSON), client (JSON) or quote (number), note, logos[i] (by position index). */
    public function createQuote(Request $request, StaffQuotes $quotes): JsonResponse
    {
        [$pass, $no] = $this->pass($request, 'assist');
        if ($no) {
            return $no;
        }
        $cart = json_decode((string) $request->input('cart'), true);
        $client = json_decode((string) $request->input('client', 'null'), true);
        $mimes = implode(',', config('website.orders.logo_mimes'));
        $v = Validator::make(['cart' => $cart, 'client' => $client] + $request->only(['quote', 'note']) + ['logos' => $request->file('logos', [])], [
            'cart' => 'required|array',
            'cart.product_id' => 'required|integer|min:1',
            'cart.lines' => 'required|array|min:1|max:'.(int) config('website.orders.max_lines'),
            'cart.lines.*.product_id' => 'required|integer|min:1',
            'cart.lines.*.colour' => 'required|string|max:120',
            'cart.lines.*.size' => 'required|string|max:60',
            'cart.lines.*.qty' => 'required|integer|min:0|max:'.(int) config('website.orders.max_units'),
            'cart.positions' => 'present|array|max:'.(int) config('website.orders.max_positions'),
            'cart.positions.*.name' => 'required|string|max:60',
            'cart.positions.*.decID' => 'required|string|max:190',
            'cart.positions.*.colours' => 'nullable|integer|min:1|max:16',
            'cart.positions.*.width_mm' => 'nullable|integer|min:5|max:1000',
            'cart.options' => 'nullable|array|max:20',
            'cart.options.*.id' => 'required|string|max:190',
            'cart.options.*.n' => 'nullable|integer|min:1|max:20',
            'quote' => 'nullable|string|max:24',
            'client' => 'nullable|array|required_without:quote',
            'client.name' => 'required_with:client|string|max:120',
            'client.organisation' => 'nullable|string|max:160',
            'client.email' => 'required_with:client|email:rfc|max:190',
            'client.phone' => 'nullable|string|max:40',
            'client.address' => 'nullable|array',
            'note' => 'nullable|string|max:2000',
            'logos' => 'array|max:8',
            'logos.*' => "file|mimes:{$mimes}|max:".(int) config('website.orders.logo_max_kb'),
        ], ['client.required_without' => 'Choose or add the client first.']);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check the quote details.', 'errors' => $v->errors()], 422);
        }
        $into = null;
        if ($request->filled('quote')) {
            $into = Quote::query()->where('number', $request->input('quote'))->where('kind', 'quote')->first();
            if (! $into) {
                return $this->reply($request, ['success' => false, 'message' => 'That quote was not found.'], 404);
            }
        }
        $cart['lines'] = array_values(array_filter($cart['lines'], fn ($l) => (int) $l['qty'] > 0));
        try {
            $r = $quotes->build($pass->user, $cart, $client, $into, (array) $request->file('logos', []), $request->input('note'));
        } catch (Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                report($e);
            }

            return $this->reply($request, ['success' => false, 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Could not make the quote just now.'], 422);
        }
        $q = $r['quote'];

        return $this->reply($request, ['success' => true, 'number' => $q->number, 'id' => $q->id, 'added' => $r['added'], 'total' => (float) $q->total,
            'url' => route('quotes.show', $q), 'issues' => array_column($r['issues'], 'message'), 'existing' => (bool) $into]);
    }
}
