<?php

namespace App\Livewire\Admin;

use App\Livewire\Attributes\Can;
use App\Models\AiLedgerEntry;
use App\Models\AiSpendingLimit;
use App\Models\Book;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * AI costs (task 7.5, from Req 22.3–22.5): what AI has cost, by job type, by book and
 * language, and per call, read from ai_ledger. Read-only; limits are set in settings
 * (task 41.3) and shown here against what has been spent.
 */
class AiCosts extends Component
{
    /** this_month | last_month | all */
    public string $period = 'this_month';

    #[Can('ai_costs.view')]
    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['this_month', 'last_month', 'all'], true) ? $period : 'this_month';
    }

    private function inPeriod(): Builder
    {
        $q = AiLedgerEntry::query();

        return match ($this->period) {
            'last_month' => $q->whereBetween('at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()]),
            'all' => $q,
            default => $q->where('at', '>=', now()->startOfMonth()),
        };
    }

    public function render()
    {
        $rows = $this->inPeriod();

        $totals = (clone $rows)->selectRaw('COALESCE(SUM(cost_zar), 0) AS cost, COUNT(*) AS calls,
            SUM(CASE WHEN cache_hit THEN 1 ELSE 0 END) AS hits')->first();

        // What reused answers would have cost: the paid price of the same question.
        $paidByHash = AiLedgerEntry::query()->where('cache_hit', false)->whereNotNull('input_hash')
            ->selectRaw('input_hash, MAX(cost_zar) AS cost')->groupBy('input_hash');
        $saved = (float) (clone $rows)->where('cache_hit', true)
            ->joinSub($paidByHash, 'paid', 'paid.input_hash', '=', 'ai_ledger.input_hash')
            ->sum('paid.cost');

        $limits = AiSpendingLimit::all();
        $monthTotal = $limits->firstWhere('operation', null);
        $byOperation = (clone $rows)->selectRaw('operation, COUNT(*) AS calls, COALESCE(SUM(cost_zar), 0) AS cost')
            ->groupBy('operation')->orderByDesc('cost')->get()
            ->map(fn ($r) => (object) [
                'operation' => $r->operation,
                'name' => AiLedgerEntry::operationName($r->operation),
                'calls' => (int) $r->calls,
                'cost' => (float) $r->cost,
                'limit' => $limits->firstWhere('operation', $r->operation)?->monthly_limit_zar,
                'spent_this_month' => AiLedgerEntry::spentThisMonth($r->operation),
            ]);

        $byEdition = (clone $rows)->selectRaw('book_id, translation_id, COUNT(*) AS calls,
                SUM(CASE WHEN cache_hit THEN 1 ELSE 0 END) AS hits, COALESCE(SUM(cost_zar), 0) AS cost')
            ->groupBy('book_id', 'translation_id')->orderByDesc('cost')->limit(50)->get();
        $books = Book::whereIn('id', $byEdition->pluck('book_id')->filter())->get(['id', 'title', 'page_count'])->keyBy('id');
        $editions = Translation::whereIn('id', $byEdition->pluck('translation_id')->filter())->get(['id', 'language_name'])->keyBy('id');

        $recent = (clone $rows)->with(['book:id,title', 'translation:id,language_name'])->latest('at')->latest('id')->limit(25)->get();

        return view('livewire.admin.ai-costs', [
            'totalCost' => (float) $totals->cost,
            'totalCalls' => (int) $totals->calls,
            'cacheHits' => (int) $totals->hits,
            'saved' => $saved,
            'spentThisMonth' => AiLedgerEntry::spentThisMonth(),
            'spentLastMonth' => (float) AiLedgerEntry::whereBetween('at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])->sum('cost_zar'),
            'monthlyLimit' => $monthTotal?->monthly_limit_zar,
            'byOperation' => $byOperation,
            'byEdition' => $byEdition,
            'books' => $books,
            'editions' => $editions,
            'recent' => $recent,
            'usdToZar' => (float) config('ai.usd_to_zar'),
        ])->layout('layouts.admin');
    }
}
