<?php

namespace Tests\Feature;

use App\Livewire\Admin\AiCosts;
use App\Models\AiLedgerEntry;
use App\Models\AiSpendingLimit;
use App\Models\Book;
use App\Models\Translation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/** Task 7.5: the publisher can see what AI has cost, by job, book and language. */
class AiCostsPageTest extends TestCase
{
    use ActsAsSuperAdmin, RefreshDatabase;

    private Book $book;

    private Translation $af;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create(['title' => "Kolulu's Home", 'page_count' => 16, 'pdf_path' => 'k.pdf']);
        $this->af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
    }

    private function ledger(string $operation, float $cost, array $extra = []): AiLedgerEntry
    {
        return AiLedgerEntry::create($extra + ['provider' => 'openai', 'model' => 'gpt-4o', 'operation' => $operation,
            'book_id' => $this->book->id, 'translation_id' => $this->af->id, 'units' => [], 'cost_zar' => $cost,
            'duration_ms' => 1500, 'input_hash' => md5($operation.$cost), 'at' => now()]);
    }

    public function test_it_shows_spend_by_job_and_by_book_language(): void
    {
        $this->ledger('translate', 4.00);
        $this->ledger('translate', 2.00);
        $this->ledger('art_repair', 3.20);
        $this->ledger('translate', 0, ['cache_hit' => true, 'input_hash' => md5('translate4')]); // reuse of the R4 answer

        Livewire::test(AiCosts::class)
            ->assertViewHas('totalCost', 9.2)
            ->assertViewHas('totalCalls', 4)
            ->assertViewHas('cacheHits', 1)
            ->assertViewHas('saved', 4.0)
            ->assertViewHas('byOperation', fn ($ops) => $ops->pluck('cost', 'name')->all() === ['Translation' => 6.0, 'Artwork repair' => 3.2])
            ->assertSeeInOrder(['R9.20', 'Translation', 'R6.00', 'Artwork repair', 'R3.20'])
            ->assertSee("Kolulu's Home")
            ->assertSee('Afrikaans')
            ->assertSee('R0.58')   // R9.20 over 16 pages
            ->assertSee('reused');
    }

    public function test_periods_split_this_month_from_earlier_spend(): void
    {
        $this->ledger('translate', 5.00);
        $this->ledger('narrate', 7.00, ['at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);

        $page = Livewire::test(AiCosts::class)->assertViewHas('totalCost', 5.0);
        $page->call('setPeriod', 'last_month')->assertViewHas('totalCost', 7.0)->assertSee('Narration');
        $page->call('setPeriod', 'all')->assertViewHas('totalCost', 12.0);
        $page->call('setPeriod', 'nonsense')->assertSet('period', 'this_month');
    }

    public function test_it_shows_the_monthly_limit_and_when_it_is_reached(): void
    {
        Livewire::test(AiCosts::class)->assertSee('No limit set');

        AiSpendingLimit::create(['operation' => null, 'monthly_limit_zar' => 10]);
        AiSpendingLimit::create(['operation' => 'translate', 'monthly_limit_zar' => 8]);
        $this->ledger('translate', 6.00);
        Livewire::test(AiCosts::class)->assertSee('R6.00')->assertSee('of R10.00')->assertSee('60% used')
            ->assertSee('R6.00 of R8.00');

        $this->ledger('translate', 4.00);
        Livewire::test(AiCosts::class)->assertSee('Limit reached');
    }

    public function test_an_empty_ledger_says_so(): void
    {
        Livewire::test(AiCosts::class)->assertSee('R0.00')->assertSee('No AI calls in this period.');
    }

    public function test_only_staff_allowed_to_see_costs_reach_the_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->get(route('admin.ai-costs'))->assertOk()->assertSee('AI costs');

        $reviewer = User::factory()->create();
        $reviewer->assignRole('Language Reviewer');
        $this->actingAs($reviewer)->get(route('admin.ai-costs'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.dashboard'))->assertDontSee('AI Costs');

        $manager = User::factory()->create();
        $manager->assignRole('Content Manager');
        $this->actingAs($manager)->get(route('admin.ai-costs'))->assertOk();
        $this->actingAs($manager)->get(route('admin.dashboard'))->assertSee('AI Costs');
    }
}
