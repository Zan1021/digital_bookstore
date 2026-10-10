<?php

namespace Tests\Feature;

use App\Livewire\Admin\BookUpload;
use App\Models\Book;
use App\Models\Translation;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Upload wizard flow (redesign): Upload → Crop → Fonts → Translate → Create → Done.
 * Verifies the two NEW steps — the in-wizard font policy is applied to the created book,
 * and the chosen language carries to the Done step + the deliberate "Translate now" action.
 *
 * The heavy PDF engine (PdfService::processUpload) is mocked so no Python runs; translation
 * dispatch is asserted via the queue, not actually executed (no OpenAI).
 */
class BookUploadWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // Approved fonts the wizard offers — stub the fonts dir with two families.
        $fontsDir = storage_path('app/fonts');
        if (!is_dir($fontsDir)) {
            @mkdir($fontsDir, 0755, true);
        }
        // Book::approvedFontAssets() derives families from the files present; create stubs.
        foreach (['PlaypenSans-Regular.ttf', 'Grade 1 Font.ttf'] as $f) {
            @file_put_contents("{$fontsDir}/{$f}", 'STUBFONT');
        }
    }

    protected function tearDown(): void
    {
        foreach (['PlaypenSans-Regular.ttf', 'Grade 1 Font.ttf'] as $f) {
            @unlink(storage_path("app/fonts/{$f}"));
        }
        Mockery::close();
        parent::tearDown();
    }

    /** Mock PdfService so processUpload returns a real Book row without the Python engine. */
    private function mockPdfService(): void
    {
        $svc = Mockery::mock(PdfService::class);
        $svc->shouldReceive('detectCropMarks')->andReturn(['detected' => false]);
        $svc->shouldReceive('processUpload')->andReturnUsing(function () {
            return Book::create([
                'title' => 'Wizard Book', 'original_language' => 'en', 'page_count' => 10,
                'status' => 'draft', 'pdf_path' => 'books/pdfs/w.pdf',
            ]);
        });
        $this->app->instance(PdfService::class, $svc);
    }

    public function test_wizard_has_fonts_and_translate_steps_in_order(): void
    {
        $this->mockPdfService();
        Livewire::test(BookUpload::class)
            ->set('currentStep', 'crop')
            ->call('nextStep')->assertSet('currentStep', 'fonts')
            ->call('nextStep')->assertSet('currentStep', 'translate');
    }

    public function test_font_policy_chosen_in_wizard_is_applied_to_the_created_book(): void
    {
        $this->mockPdfService();
        Bus::fake();

        $file = UploadedFile::fake()->create('My Book.pdf', 100, 'application/pdf');

        Livewire::test(BookUpload::class)
            ->set('files', [$file])
            ->set('bodyFont', 'PlaypenSans')
            ->set('titleFont', 'PlaypenSans')
            ->set('targetLanguage', 'af')
            ->set('currentStep', 'translate')
            ->call('nextStep') // translate → processing → done
            ->assertSet('currentStep', 'done');

        $book = Book::where('title', 'Wizard Book')->first();
        $this->assertNotNull($book);
        $policy = $book->metadata['typography_policy'] ?? [];
        $this->assertSame('PlaypenSans', $policy['roles']['body']['font_asset_id'] ?? null);
        $this->assertSame('PlaypenSans', $policy['roles']['title']['font_asset_id'] ?? null);
    }

    public function test_translate_now_dispatches_translation_for_chosen_language(): void
    {
        $this->mockPdfService();
        Bus::fake();

        $file = UploadedFile::fake()->create('My Book.pdf', 100, 'application/pdf');

        $component = Livewire::test(BookUpload::class)
            ->set('files', [$file])
            ->set('targetLanguage', 'af')
            ->set('currentStep', 'translate')
            ->call('nextStep')          // creates the book, lands on 'done'
            ->call('translateCreated'); // the deliberate paid action

        // A translation edition was created for the chosen language…
        $book = Book::where('title', 'Wizard Book')->first();
        $edition = Translation::where('book_id', $book->id)->where('language_code', 'af')->first();
        $this->assertNotNull($edition, 'an Afrikaans edition should be created');
        // …and the translate job was dispatched (Bus::fake intercepts dispatchSync → no OpenAI).
        Bus::assertDispatched(\App\Jobs\TranslateEditionJob::class);
    }

    public function test_no_language_means_no_translation_dispatched(): void
    {
        $this->mockPdfService();
        Bus::fake();

        $file = UploadedFile::fake()->create('My Book.pdf', 100, 'application/pdf');

        Livewire::test(BookUpload::class)
            ->set('files', [$file])
            ->set('targetLanguage', '') // skip / translate later
            ->set('currentStep', 'translate')
            ->call('nextStep')
            ->call('translateCreated');

        Bus::assertNotDispatched(\App\Jobs\TranslateEditionJob::class);
    }
}
