<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Staff: website project requests (docs/website-project-requests.md). */
class ProjectRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), ProjectRequest::STATUSES) ? $request->query('status') : null;
        $q = trim((string) $request->query('q'));
        $requests = ProjectRequest::query()
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('organisation', 'like', "%{$q}%")
                ->orWhere('brief', 'like', "%{$q}%")))
            ->orderByRaw("case when status = 'new' then 0 else 1 end")->orderBy('reply_by')->latest('id')
            ->paginate(50)->withQueryString();

        return view('project-requests.index', compact('requests', 'status', 'q'));
    }

    public function show(ProjectRequest $projectRequest)
    {
        return view('project-requests.show', ['r' => $projectRequest]);
    }

    public function update(Request $request, ProjectRequest $projectRequest)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ProjectRequest::STATUSES))],
            'staff_notes' => 'nullable|string|max:5000',
        ]);
        $projectRequest->update($data);
        AuditLog::record('project_request.updated', $projectRequest, ['status' => $data['status']]);

        return back()->with('status', 'Saved.');
    }

    public function file(ProjectRequest $projectRequest, int $n)
    {
        $f = $projectRequest->files[$n] ?? null;
        abort_unless($f, 404);
        $disk = Storage::disk(config('website.projects.disk'));
        abort_unless($disk->exists($f['path']), 404);
        AuditLog::record('project_request.file_downloaded', $projectRequest, ['file' => $f['name']]);

        return $disk->download($f['path'], $f['name'], ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }
}
