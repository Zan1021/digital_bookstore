<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBookClassificationJob;
use App\Models\Book;
use App\Models\BookDescription;
use App\Models\BookPage;
use App\Models\ClassificationSuggestion;
use App\Models\Tag;
use App\Services\Classification\LlmClient;
use Database\Seeders\ClassificationVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A fake LLM returning deterministic subjective output so we can assert the pipeline. */
class FakeLlmClient implements LlmClient
{
    public function classify(array $context): array
    {
        return [
            'themes' => ['Friendship'],
            'topics' => ['Animals'],
            'characters' => ['Kolulu'],
            'setting' => 'Home',
            'mood' => 'Funny',
            'genres' => ['Adventure'],
            'tags' => ['friendship', 'brand-new-theme'], // one canonical, one unknown -> pending
        ];
    }

    public function describe(array $context): array
    {
        return ['short' => 'A warm story about friendship. Email me@x.com', 'long' => 'Longer blurb.'];
    }
}

class ClassificationPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClassificationVocabularySeeder::class);
        $this->app->bind(LlmClient::class, FakeLlmClient::class);
    }

    private function makeBook(): Book
    {
        $book = Book::create([
            'title' => 'A Fun Place', 'pdf_path' => 'x.pdf', 'page_count' => 12,
            'status' => 'ready', 'original_language' => 'en',
        ]);
        for ($i = 1; $i <= 12; $i++) {
            BookPage::create(['book_id' => $book->id, 'page_number' => $i,
                'extracted_text' => 'The cat ran home.']);
        }
        return $book;
    }

    public function test_analysis_job_produces_suggestions_and_description(): void
    {
        $book = $this->makeBook();

        AnalyzeBookClassificationJob::dispatchSync($book->id);
        $book->refresh();

        $this->assertSame('suggested', $book->classification_status);

        // Deterministic dimensions present.
        $this->assertDatabaseHas('classification_suggestions',
            ['book_id' => $book->id, 'field' => 'book_type']);
        $this->assertDatabaseHas('classification_suggestions',
            ['book_id' => $book->id, 'field' => 'reading_level']);

        // Age range is flagged as requiring confirmation (safeguarding).
        $age = ClassificationSuggestion::where('book_id', $book->id)->where('field', 'age_range')->first();
        $this->assertNotNull($age);
        $this->assertTrue((bool) $age->requires_confirmation);

        // Subjective LLM dimensions present.
        $this->assertDatabaseHas('classification_suggestions',
            ['book_id' => $book->id, 'field' => 'theme']);
        $this->assertDatabaseHas('classification_suggestions',
            ['book_id' => $book->id, 'field' => 'mood']);

        // Description drafted, status suggested, PII (email) stripped.
        $desc = BookDescription::where('book_id', $book->id)->first();
        $this->assertNotNull($desc);
        $this->assertSame('suggested', $desc->status);
        $this->assertStringNotContainsString('@', $desc->short_text);
    }

    public function test_tag_resolution_matches_canonical_and_queues_unknown_as_pending(): void
    {
        $book = $this->makeBook();
        AnalyzeBookClassificationJob::dispatchSync($book->id);

        // 'friendship' maps to the seeded canonical active tag.
        $friendship = Tag::where('slug', 'friendship')->first();
        $this->assertSame('active', $friendship->status);
        $this->assertDatabaseHas('classification_suggestions', [
            'book_id' => $book->id, 'field' => 'tag',
        ]);

        // 'brand-new-theme' had no match -> created as a PENDING tag (approval queue),
        // never active.
        $pending = Tag::where('slug', 'brand-new-theme')->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', $pending->status);
    }

    public function test_failure_sets_failed_status_but_does_not_lose_book(): void
    {
        // Bind an LLM that throws to simulate a provider outage.
        $this->app->bind(LlmClient::class, function () {
            return new class implements LlmClient {
                public function classify(array $c): array { throw new \RuntimeException('llm down'); }
                public function describe(array $c): array { return ['short' => '', 'long' => '']; }
            };
        });
        $book = $this->makeBook();

        try {
            AnalyzeBookClassificationJob::dispatchSync($book->id);
        } catch (\Throwable $e) {
            // sync dispatch rethrows; expected.
        }
        $book->refresh();
        $this->assertSame('failed', $book->classification_status);
        $this->assertDatabaseHas('books', ['id' => $book->id]); // book still there
    }
}
