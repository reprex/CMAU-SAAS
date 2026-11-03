<?php

namespace App\Mail\Concerns;

use App\Catalogue\TemplateFiles;
use Illuminate\Mail\Attachment;

/**
 * The products' branding templates, attached to a quote or invoice email (docs/branding-templates.md):
 * the only way they reach a client, as they are never linked from our product pages.
 */
trait AttachesTemplates
{
    /** @var array{files: list<array{name: string, data: string, mime: string}>, left_out: list<string>}|null */
    private ?array $templateFiles = null;

    /** @return list<int> */
    abstract protected function templateProductIds(): array;

    /** @return array{files: list<array{name: string, data: string, mime: string}>, left_out: list<string>} */
    public function templates(): array
    {
        return $this->templateFiles ??= app(TemplateFiles::class)->attachments($this->templateProductIds());
    }

    /** @return list<Attachment> */
    protected function templateAttachments(): array
    {
        return array_map(fn ($f) => Attachment::fromData(fn () => $f['data'], $f['name'])->withMime($f['mime']), $this->templates()['files']);
    }
}
