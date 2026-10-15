<?php

namespace Tests\Feature;

use App\Livewire\Admin\BookManager;
use App\Livewire\Admin\BookUpload;
use App\Models\Book;
use App\Models\Language;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Task 6.3 (design §4): languages are data — code, name, direction, script,
 * hyphenation, enabled — and every language list in the app reads from them.
 */
class LanguagesTest extends TestCase
{
    use ActsAsSuperAdmin, RefreshDatabase;

    /** The 15 targets that were hard-coded in TranslationService before task 6.3. */
    private const PREVIOUS_TARGETS = ['af', 'zu', 'xh', 'st', 'nso', 'tn', 'fr', 'de', 'es', 'pt', 'nl', 'it', 'sw', 'ar', 'zh'];

    private function book(): Book
    {
        return Book::create(['title' => 'L', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/l.pdf']);
    }

    public function test_the_seed_keeps_every_language_offered_before(): void
    {
        // Same languages, same order as the old dropdowns.
        $this->assertSame(self::PREVIOUS_TARGETS, array_keys(Language::targetOptions()));
        $this->assertSame('English', Language::nameFor('en'));
        $this->assertSame('Sepedi', Language::nameFor('nso'));
    }

    public function test_direction_and_script_are_recorded(): void
    {
        $this->assertTrue(Language::find('ar')->isRtl());
        $this->assertSame('Arab', Language::find('ar')->script);
        $this->assertSame('Hans', Language::find('zh')->script);
        $this->assertFalse(Language::find('af')->isRtl());
        $this->assertTrue(Language::find('af')->hyphenation);
    }

    public function test_target_options_exclude_the_source_and_disabled_languages(): void
    {
        Language::find('de')->update(['enabled' => false]);

        $options = Language::targetOptions('en');
        $this->assertArrayNotHasKey('en', $options);
        $this->assertArrayNotHasKey('de', $options);
        $this->assertArrayHasKey('af', $options);
        $this->assertArrayHasKey('en', Language::targetOptions('af'), 'an Afrikaans book can go to English');
    }

    public function test_translation_refuses_disabled_unknown_or_source_languages(): void
    {
        Language::find('de')->update(['enabled' => false]);
        $translator = app(TranslationService::class);

        foreach (['de', 'xx', 'en'] as $code) {
            try {
                $translator->translate($this->book(), $code);
                $this->fail("translating into '$code' must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("Unsupported language: $code", $e->getMessage());
            }
        }
    }

    public function test_unknown_codes_still_display_without_failing(): void
    {
        $this->assertSame('xx', Language::nameFor('xx'));
    }

    public function test_admin_language_lists_come_from_the_table(): void
    {
        Language::find('de')->update(['enabled' => false]);
        Language::create(['code' => 've', 'name' => 'Tshivenda', 'script' => 'Latn']);

        $manager = Livewire::test(BookManager::class, ['book' => $this->book()])->set('activeTab', 'translate');
        $manager->assertSee('Tshivenda')->assertDontSee('German');

        $upload = Livewire::test(BookUpload::class);
        $this->assertArrayHasKey('ve', $upload->get('languageOptions'));
        $this->assertArrayNotHasKey('de', $upload->get('languageOptions'));
    }
}
