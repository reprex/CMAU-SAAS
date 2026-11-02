<?php

namespace App\Projects;

use App\Checkout\BusinessDays;
use App\Jobs\SendProjectRequestEmails;
use App\Models\AuditLog;
use App\Models\ProjectRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saves a website project request with its files (private disk), works out the reply-by
 * date (one working day, VIC public holidays skipped) and queues the emails.
 */
class ProjectRequests
{
    public function __construct(private readonly BusinessDays $days) {}

    /**
     * @param  array<string, mixed>  $data  validated
     * @param  list<UploadedFile>  $files
     */
    public function create(array $data, array $files, ?string $ip, ?string $source): ProjectRequest
    {
        $cfg = config('website.projects');
        $tz = config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $replyBy = $this->days->add($now, (int) $cfg['reply_working_days'], $cfg['reply_state']);
        $stored = [];

        try {
            $request = DB::transaction(function () use ($data, $replyBy, $ip, $source) {
                $r = ProjectRequest::query()->create([
                    'number' => 'tmp-'.Str::random(10),
                    'brief' => $data['brief'],
                    'kinds' => array_values(array_intersect((array) ($data['kinds'] ?? []), array_keys(config('website.projects.kinds')))) ?: null,
                    'quantity' => $data['quantity'],
                    'budget' => $data['budget'] ?? null,
                    'need_by' => $data['need_by'] ?? null,
                    'event' => $data['event'] ?? null,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? null,
                    'organisation' => $data['organisation'] ?? null,
                    'email' => mb_strtolower($data['email']),
                    'phone' => $data['phone'],
                    'contact_by' => $data['contact_by'] ?? null,
                    'reply_by' => $replyBy->toDateString(),
                    'source_url' => $source ? mb_substr($source, 0, 300) : null,
                    'ip_hash' => $ip ? hash('sha256', $ip) : null,
                ]);
                $r->forceFill(['number' => config('website.projects.number_prefix').(config('website.projects.number_start') + $r->id - 1)])->save();

                return $r;
            });
            $disk = Storage::disk($cfg['disk']);
            foreach ($files as $i => $file) {
                $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
                $path = 'project-requests/'.$request->number.'/'.($i + 1).'-'.Str::random(8).'.'.$ext;
                $disk->putFileAs(dirname($path), $file, basename($path));
                $stored[] = ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 160), 'size' => (int) $file->getSize()];
            }
            if ($stored) {
                $request->forceFill(['files' => $stored])->save();
            }
        } catch (Throwable $e) {
            foreach ($stored as $f) {
                Storage::disk($cfg['disk'])->delete($f['path']);
            }
            throw $e;
        }

        AuditLog::record('project_request.created', $request, ['files' => count($stored)]);
        SendProjectRequestEmails::dispatch($request->id);

        return $request;
    }
}
