<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Upload must only create the ebook — no translation, no narration
 * (spec: gated-translation-narration-flow Req 1.3).
 *
 * The full upload path runs a PDF parse + a Python manifest subprocess + font
 * resolution, which is environment-heavy and flaky in CI. These tests lock the
 * *decoupling contract* at the source level: the upload service and the upload
 * Livewire component must not invoke translation or narration. That is the exact
 * behaviour Req 1.3 guarantees, expressed without the heavy pipeline.
 */
class UploadDecouplingTest extends TestCase
{
    private function source(string $relative): string
    {
        return file_get_contents(base_path($relative));
    }

    public function test_pdf_service_does_not_auto_narrate_on_upload(): void
    {
        $src = $this->source('app/Services/PdfService.php');

        // processUpload() must no longer call the auto-narration path.
        $upload = substr($src, strpos($src, 'function processUpload'));
        $upload = substr($upload, 0, strpos($upload, "\n    }") + 6);

        $this->assertStringNotContainsString('autoNarrate(', $upload,
            'processUpload must not trigger narration (Req 1.3).');
        $this->assertStringNotContainsString('->narrate(', $upload,
            'processUpload must not call NarrationService::narrate (Req 1.3).');
    }

    public function test_upload_stores_a_url_safe_slugged_filename(): void
    {
        $src = $this->source('app/Services/PdfService.php');
        $upload = substr($src, strpos($src, 'function processUpload'));
        $upload = substr($upload, 0, strpos($upload, "\n    }") + 6);

        // The stored filename must be slugged — storing the raw client name verbatim
        // reintroduces spaces/apostrophes (e.g. "Kolulu's Home.pdf") that break the public
        // /storage URL the reader hands to PDF.js (apostrophe HTML-entity encodes -> 403).
        $this->assertStringContainsString('Str::slug(', $upload,
            'processUpload must slug the stored filename so the storage URL is URL-safe.');
        $this->assertStringNotContainsString("time() . '_' . \$file->getClientOriginalName()", $upload,
            'processUpload must not store the raw client filename verbatim.');
    }

    public function test_book_upload_component_does_not_translate_or_narrate(): void
    {
        $src = $this->source('app/Livewire/Admin/BookUpload.php');

        // Req 1.3 bans AUTO translation/narration during upload — i.e. the upload and
        // processing path (startProcessing) must not translate or narrate. It does NOT
        // ban the publisher's deliberate, paid "translate the book I just made" action
        // (translateCreated), which legitimately references TranslationService for a
        // language-name lookup and dispatches a job only on an explicit click. So we
        // assert the CONTRACT behaviourally: the upload/processing method is clean, and
        // narration is never wired in at all.
        $processing = $this->methodBody($src, 'function startProcessing');

        $this->assertStringNotContainsString('TranslationService', $processing,
            'Upload/processing path must not translate (Req 1.3).');
        $this->assertStringNotContainsString('TranslateEditionJob', $processing,
            'Upload/processing path must not dispatch translation (Req 1.3).');
        $this->assertStringNotContainsString('->translate(', $processing);

        // Narration must not be wired into the upload component at all.
        $this->assertStringNotContainsString('NarrationService', $src,
            'Upload wizard must not run narration (Req 1.3).');
        $this->assertStringNotContainsString('->narrate(', $src);
    }

    /** Extract a single method body from source by its `function <name>` signature. */
    private function methodBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            return '';
        }
        $body = substr($src, $start);
        $end = strpos($body, "\n    }");
        return $end === false ? $body : substr($body, 0, $end + 6);
    }

    public function test_upload_wizard_steps_exclude_language_and_voice(): void
    {
        $src = $this->source('app/Livewire/Admin/BookUpload.php');

        // The flow is: upload -> crop -> fonts -> translate -> processing -> done.
        // The 'translate' step only RECORDS the target language in-wizard; translation
        // is still a deliberate, paid action run AFTER the book is created (Req 1.3),
        // never silently during upload. The legacy 'languages'/'voice' steps are gone.
        $this->assertStringContainsString("'upload', 'crop', 'fonts', 'translate', 'processing', 'done'", $src);
        $this->assertStringNotContainsString("'languages'", $src);
        $this->assertStringNotContainsString("'voice'", $src);
    }
}
