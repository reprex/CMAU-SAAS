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
use App\Staff\StaffPasses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
}
