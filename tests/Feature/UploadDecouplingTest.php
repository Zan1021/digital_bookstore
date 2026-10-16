<?php

namespace Tests\Feature;

use App\Jobs\IntakeBookJob;
use App\Jobs\TranslateEditionJob;
use App\Livewire\Admin\Intake;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Upload must only create the ebook — no translation, no narration
 * (spec: gated-translation-narration-flow Req 1.3). Translation and narration are deliberate
 * actions on the book's page; translating many books at once is the bulk action of task 39.
 */
class UploadDecouplingTest extends TestCase
{
    use ActsAsSuperAdmin, RefreshDatabase;

    private function source(string $relative): string
    {
        return file_get_contents(base_path($relative));
    }

    private function methodBody(string $src, string $signature): string
    {
        $body = substr($src, (int) strpos($src, $signature));

        return substr($body, 0, (int) strpos($body, "\n    }") + 6);
    }

    public function test_pdf_service_does_not_auto_narrate_on_upload(): void
    {
        $src = $this->source('app/Services/PdfService.php');

        foreach (['function processUploadUntimed', 'function processBookUntimed', 'function createBookFromUpload'] as $method) {
            $body = $this->methodBody($src, $method);
            $this->assertNotSame('', trim($body), "{$method} exists");
            $this->assertStringNotContainsString('autoNarrate(', $body, "{$method} must not trigger narration (Req 1.3).");
            $this->assertStringNotContainsString('->narrate(', $body, "{$method} must not call NarrationService::narrate (Req 1.3).");
        }
    }

    public function test_upload_stores_a_url_safe_slugged_filename(): void
    {
        // The stored filename must be slugged — storing the raw client name verbatim
        // reintroduces spaces/apostrophes (e.g. "Kolulu's Home.pdf") that break storage URLs
        // (apostrophe HTML-entity encodes -> 403). The title still comes from the real name.
        Storage::fake(config('assets.disk'));
        $file = UploadedFile::fake()->create("Kolulu's Home.pdf", 10, 'application/pdf');

        $book = app(PdfService::class)->storeIntake($file);

        $this->assertMatchesRegularExpression('~^books/pdfs/\d+_[a-z0-9]{6}_kolulus-home\.pdf$~', $book->pdf_path);
        $this->assertTrue(Storage::disk(config('assets.disk'))->exists($book->pdf_path));
        $this->assertStringContainsString("Kolulu's Home", $book->title);
    }

    public function test_intake_queues_only_the_intake_job(): void
    {
        Storage::fake(config('assets.disk'));
        Queue::fake();

        Livewire::test(Intake::class)
            ->set('upload', UploadedFile::fake()->create('Play with me.pdf', 20, 'application/pdf'))
            ->call('finishUpload', 'Play with me.pdf');

        Queue::assertPushed(IntakeBookJob::class, 1);
        Queue::assertNotPushed(TranslateEditionJob::class);
    }

    public function test_the_intake_code_has_no_translation_or_narration_hooks(): void
    {
        foreach (['app/Livewire/Admin/Intake.php', 'app/Jobs/IntakeBookJob.php'] as $file) {
            $src = $this->source($file);
            foreach (['TranslationService', 'TranslateEditionJob', 'NarrationService', '->narrate(', '->translate('] as $banned) {
                $this->assertStringNotContainsString($banned, $src, "{$file} must not reference {$banned} (Req 1.3).");
            }
        }
    }
}
