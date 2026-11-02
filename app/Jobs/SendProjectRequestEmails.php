<?php

namespace App\Jobs;

use App\Mail\ProjectRequestConfirmationMail;
use App\Mail\ProjectRequestStaffMail;
use App\Models\AuditLog;
use App\Models\ProjectRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The client's confirmation (their request, and when we'll reply) and the staff notice.
 * Runs within the minute cron (queue:work). Each email is sent once.
 */
class SendProjectRequestEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $requestId) {}

    public function handle(): void
    {
        $r = ProjectRequest::find($this->requestId);
        if ($r === null) {
            return;
        }
        try {
            if (! $r->confirmation_sent_at && config('website.projects.send_confirmation')) {
                Mail::to($r->email)->send(new ProjectRequestConfirmationMail($r));
                $r->forceFill(['confirmation_sent_at' => now(), 'email_error' => null])->save();
                AuditLog::record('project_request.confirmation_sent', $r, ['to' => $r->email]);
            }
            if (! $r->staff_notified_at && ($staff = config('website.projects.staff_email'))) {
                Mail::to($staff)->send(new ProjectRequestStaffMail($r));
                $r->forceFill(['staff_notified_at' => now()])->save();
            }
        } catch (Throwable $e) {
            $r->forceFill(['email_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }
}
