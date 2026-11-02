<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyCurationRequest;
use App\Models\AuditLog;
use App\Models\CurationRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff: range changes asked for from the website's product page (docs/website-staff.md). */
class CurationRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), CurationRequest::STATUSES) ? $request->query('status') : null;
        $requests = CurationRequest::query()->with(['user', 'decider', 'product'])
            ->when($status, fn ($w) => $w->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 when status = 'failed' then 1 else 2 end")->latest('id')
            ->paginate(50)->withQueryString();

        return view('curation-requests.index', compact('requests', 'status'));
    }

    public function decide(Request $request, CurationRequest $curationRequest)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'retry', 'done'])]]);
        $r = $curationRequest;
        if ($data['decision'] === 'reject' || ($data['decision'] === 'done' && $r->edits === [])) {
            $r->update(['status' => $data['decision'] === 'done' ? 'applied' : 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
            AuditLog::record('curation.'.($data['decision'] === 'done' ? 'done' : 'rejected'), $r->product, ['request' => $r->id]);

            return back()->with('status', $data['decision'] === 'done' ? 'Marked done.' : 'Rejected.');
        }
        abort_unless(in_array($r->status, ['pending', 'failed'], true), 422, 'This request has already been decided.');
        $r->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'error' => null]);
        AuditLog::record('curation.approved', $r->product, ['request' => $r->id]);
        ApplyCurationRequest::dispatch($r->id);

        return back()->with('status', 'Approved. It applies and republishes within a minute or two.');
    }
}
