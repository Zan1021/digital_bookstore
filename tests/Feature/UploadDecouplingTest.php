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

    public function test_book_upload_component_does_not_translate_or_narrate(): void
    {
        $src = $this->source('app/Livewire/Admin/BookUpload.php');

        $this->assertStringNotContainsString('TranslationService', $src,
            'Upload wizard must not run translation (Req 1.3).');
        $this->assertStringNotContainsString('NarrationService', $src,
            'Upload wizard must not run narration (Req 1.3).');
        $this->assertStringNotContainsString('->translate(', $src);
        $this->assertStringNotContainsString('->narrate(', $src);
    }

    public function test_upload_wizard_steps_exclude_language_and_voice(): void
    {
        $src = $this->source('app/Livewire/Admin/BookUpload.php');

        // The flow is now upload -> crop -> processing -> done (no languages/voice steps).
        $this->assertStringContainsString("'upload', 'crop', 'processing', 'done'", $src);
        $this->assertStringNotContainsString("'languages'", $src);
        $this->assertStringNotContainsString("'voice'", $src);
    }
}
