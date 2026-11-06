<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through Microsoft Graph as one Microsoft 365 mailbox (docs/stack-and-security.md, "Email"):
 * an app registration with the Mail.Send application permission, limited to that mailbox, signs in with
 * its client secret (no password, so it keeps working after Microsoft retires SMTP basic auth). Each
 * email is saved to the mailbox's Sent Items, so the team has a record and replies land in its inbox.
 */
class GraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $tenant,
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $mailbox,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $msg = $this->message($email);
        $payload = ['message' => $msg, 'saveToSentItems' => true];
        if (strlen((string) json_encode($payload)) <= 3_000_000) {
            $this->check($this->api()->post($this->base().'/sendMail', $payload), 'sendMail');

            return;
        }
        // Over Graph's 4 MB request limit (branding templates on a quote): a draft, its attachments one by one
        // (large ones in an upload session), then send. Saved to Sent Items as usual.
        $files = $msg['attachments'] ?? [];
        unset($msg['attachments']);
        $draft = $this->check($this->api()->post($this->base().'/messages', $msg), 'create draft');
        $id = (string) $draft['id'];
        foreach ($files as $f) {
            $bytes = base64_decode($f['contentBytes']);
            if (strlen($bytes) < 2_900_000) {
                $this->check($this->api()->post($this->base().'/messages/'.$id.'/attachments', $f), 'attach '.$f['name']);

                continue;
            }
            $session = $this->check($this->api()->post($this->base().'/messages/'.$id.'/attachments/createUploadSession', [
                'AttachmentItem' => array_filter(['attachmentType' => 'file', 'name' => $f['name'], 'size' => strlen($bytes), 'contentType' => $f['contentType'] ?? null, 'isInline' => $f['isInline'] ?? false, 'contentId' => $f['contentId'] ?? null], fn ($v) => $v !== null),
            ]), 'upload session');
            $size = strlen($bytes);
            for ($start = 0; $start < $size; $start += 3_276_800) { // 10 × 320 KiB per chunk
                $chunk = substr($bytes, $start, 3_276_800);
                $end = $start + strlen($chunk) - 1;
                $r = Http::withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$size}"])->withBody($chunk, 'application/octet-stream')->timeout(60)->put((string) $session['uploadUrl']);
                if (! $r->successful()) {
                    throw new RuntimeException('Microsoft Graph upload failed ('.$r->status().') for '.$f['name']);
                }
            }
        }
        $this->check($this->api()->post($this->base().'/messages/'.$id.'/send'), 'send');
    }

    private function base(): string
    {
        return 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->mailbox);
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(60);
    }

    /** @return array<string, mixed> */
    private function check(Response $r, string $what): array
    {
        if ($r->status() === 401) {
            Cache::forget($this->cacheKey());
        }
        if (! $r->successful()) {
            throw new RuntimeException("Microsoft Graph {$what} failed (".$r->status().'): '.mb_substr((string) ($r->json('error.message') ?? $r->body()), 0, 300));
        }

        return (array) $r->json();
    }

    /** @return array<string, mixed> */
    public function message(Email $email): array
    {
        $html = $email->getHtmlBody();
        $msg = [
            'subject' => (string) $email->getSubject(),
            'body' => $html !== null ? ['contentType' => 'HTML', 'content' => (string) $html] : ['contentType' => 'Text', 'content' => (string) $email->getTextBody()],
            'toRecipients' => $this->addresses($email->getTo()),
            'ccRecipients' => $this->addresses($email->getCc()),
            'bccRecipients' => $this->addresses($email->getBcc()),
            'replyTo' => $this->addresses($email->getReplyTo()),
        ];
        // Always the mailbox's address; the name can say who it's from ("Scott at CrowdMerch").
        $from = $email->getFrom()[0] ?? null;
        $msg['from'] = ['emailAddress' => array_filter(['address' => $this->mailbox, 'name' => $from?->getName() ?: null])];
        $files = [];
        foreach ($email->getAttachments() as $part) {
            $name = $part->getFilename() ?: 'attachment';
            $inline = $part->getDisposition() === 'inline';
            $files[] = array_filter([
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $name,
                'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'contentBytes' => base64_encode($part->getBody()),
                'isInline' => $inline,
                'contentId' => $inline ? ($part->hasContentId() ? $part->getContentId() : null) : null,
            ], fn ($v) => $v !== null);
        }
        if ($files !== []) {
            $msg['attachments'] = $files;
        }

        return array_filter($msg, fn ($v) => $v !== []);
    }

    /** @param list<Address> $list */
    private function addresses(array $list): array
    {
        return array_map(fn (Address $a) => ['emailAddress' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName() ?: null])], $list);
    }

    private function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function () {
            $r = Http::asForm()->timeout(20)->post('https://login.microsoftonline.com/'.rawurlencode($this->tenant).'/oauth2/v2.0/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new RuntimeException('Microsoft sign-in for email failed ('.$r->status().'): '.mb_substr((string) ($r->json('error_description') ?? $r->body()), 0, 300));
            }

            return (string) $r->json('access_token');
        });
    }

    private function cacheKey(): string
    {
        return 'msgraph-mail-token:'.sha1($this->tenant.'|'.$this->clientId);
    }

    public function __toString(): string
    {
        return 'graph://'.$this->mailbox;
    }
}
