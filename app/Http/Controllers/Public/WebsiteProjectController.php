<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\GuardsWebsiteOrders;
use App\Projects\ProjectRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * POST crowdmerch/projects: the website's project request form (docs/website-project-requests.md).
 * Multipart: the fields below and files[] (images, mockups, briefs).
 */
class WebsiteProjectController extends Controller
{
    use GuardsWebsiteOrders;

    public function store(Request $request, ProjectRequests $projects)
    {
        if ($blocked = $this->guard($request, 'projects', (int) config('website.projects.per_hour'), write: true)) {
            return $blocked;
        }
        $cfg = config('website.projects');
        $v = Validator::make($request->all(), [
            'brief' => 'required|string|min:5|max:4000',
            'kinds' => 'nullable|array|max:8',
            'kinds.*' => ['string', Rule::in(array_keys($cfg['kinds']))],
            'quantity' => 'required|string|max:120',
            'budget' => 'nullable|string|max:60',
            'need_by' => 'nullable|date_format:Y-m-d|after:today',
            'event' => 'nullable|string|max:160',
            'first_name' => 'required|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'organisation' => 'nullable|string|max:160',
            'email' => 'required|email:rfc|max:190',
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\s.-]{6,40}$/'],
            'contact_by' => 'nullable|in:email,phone',
            'files' => 'nullable|array|max:'.(int) $cfg['max_files'],
            'files.*' => 'file|mimes:'.implode(',', $cfg['file_mimes']).'|max:'.(int) $cfg['file_max_kb'],
        ], [
            'brief.required' => 'Tell us a little about the project.',
            'quantity.required' => 'Roughly how many do you need? A number or a description is fine.',
            'phone.regex' => 'Please enter a phone number.',
            'need_by.after' => 'The date you need it by should be in the future.',
            'files.max' => 'Up to '.$cfg['max_files'].' files, please.',
            'files.*.mimes' => 'Images, PDFs, AI/EPS, Office documents or a zip, please.',
            'files.*.max' => 'Each file can be up to '.round($cfg['file_max_kb'] / 1024).' MB.',
        ]);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check the highlighted details.', 'errors' => $v->errors()], 422);
        }
        try {
            $r = $projects->create($v->validated(), array_values((array) $request->file('files', [])), $request->ip(), $request->headers->get('Referer'));
        } catch (Throwable $e) {
            report($e);

            return $this->reply($request, ['success' => false, 'message' => 'Sorry, we could not send your request just now. Please try again, or email '.config('quotes.pdf.email').'.'], 503);
        }

        return $this->reply($request, [
            'success' => true,
            'number' => $r->number,
            'reply_by' => $r->reply_by->toDateString(),
            'reply_by_text' => $r->reply_by->format('l j F'),
            'email' => $r->email,
            'confirmation' => (bool) config('website.projects.send_confirmation'),
        ]);
    }
}
