<div class="max-w-6xl mx-auto">
    @php($rand = fn ($v) => 'R'.number_format((float) $v, 2))
    <div class="flex flex-wrap items-end justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 mb-1">AI costs</h1>
            <p class="text-sm text-gray-500">What translation, page checks, artwork repair and narration have cost, from every AI call the platform made.</p>
        </div>
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 text-sm" role="group" aria-label="Period">
            @foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'all' => 'All time'] as $value => $label)
                <button type="button" wire:click="setPeriod('{{ $value }}')" aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-md {{ $period === $value ? 'bg-brand-600 text-white' : 'text-gray-600 hover:bg-gray-100' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <section class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-xs font-medium uppercase tracking-wide text-gray-500">Spent</h2>
            <p class="text-3xl font-bold text-gray-900 mt-1" data-test="total-cost">{{ $rand($totalCost) }}</p>
            <p class="text-sm text-gray-500 mt-1">{{ number_format($totalCalls) }} AI {{ $totalCalls === 1 ? 'call' : 'calls' }}</p>
        </section>
        <section class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-xs font-medium uppercase tracking-wide text-gray-500">Reused answers</h2>
            <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($cacheHits) }}</p>
            <p class="text-sm text-gray-500 mt-1">Saved about {{ $rand($saved) }} by not asking again</p>
        </section>
        <section class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-xs font-medium uppercase tracking-wide text-gray-500">Monthly limit</h2>
            @if ($monthlyLimit)
                @php($pct = $monthlyLimit > 0 ? min(100, round($spentThisMonth / $monthlyLimit * 100)) : 100)
                <p class="text-3xl font-bold text-gray-900 mt-1">{{ $rand($spentThisMonth) }} <span class="text-base font-normal text-gray-500">of {{ $rand($monthlyLimit) }}</span></p>
                <div class="mt-3 h-2 rounded-full bg-gray-100 overflow-hidden" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Monthly limit used">
                    <div class="h-full {{ $pct >= 100 ? 'bg-red-600' : ($pct >= 80 ? 'bg-sun-400' : 'bg-leaf-500') }}" style="width: {{ $pct }}%"></div>
                </div>
                <p class="text-sm mt-2 {{ $pct >= 100 ? 'text-red-700 font-medium' : 'text-gray-500' }}">
                    {{ $pct >= 100 ? 'Limit reached: AI jobs are paused until next month or a higher limit.' : $pct.'% used' }}
                </p>
            @else
                <p class="text-3xl font-bold text-gray-400 mt-1">No limit set</p>
                <p class="text-sm text-gray-500 mt-1">This month {{ $rand($spentThisMonth) }} · last month {{ $rand($spentLastMonth) }}</p>
            @endif
        </section>
    </div>

    {{-- By job type --}}
    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <h2 class="font-semibold text-gray-800 px-5 pt-4 pb-2">By job</h2>
        @if ($byOperation->isEmpty())
            <p class="px-5 pb-5 text-sm text-gray-500">No AI calls in this period.</p>
        @else
            <div class="overflow-x-auto"><table class="w-full text-sm min-w-[40rem]">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-2 font-medium">Job</th>
                        <th class="px-5 py-2 font-medium text-right">Calls</th>
                        <th class="px-5 py-2 font-medium text-right">Cost</th>
                        <th class="px-5 py-2 font-medium w-1/3">Share</th>
                        <th class="px-5 py-2 font-medium text-right">Monthly limit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($byOperation as $op)
                        @php($share = $totalCost > 0 ? round($op->cost / $totalCost * 100) : 0)
                        <tr wire:key="op-{{ $op->operation }}">
                            <td class="px-5 py-2.5 text-gray-900">{{ $op->name }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ number_format($op->calls) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-medium">{{ $rand($op->cost) }}</td>
                            <td class="px-5 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-brand-500" style="width: {{ $share }}%"></div></div>
                                    <span class="text-xs text-gray-500 w-9 text-right tabular-nums">{{ $share }}%</span>
                                </div>
                            </td>
                            <td class="px-5 py-2.5 text-right text-gray-500 tabular-nums">
                                @if ($op->limit !== null)
                                    {{ $rand($op->spent_this_month) }} of {{ $rand($op->limit) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    {{-- By book and language --}}
    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <h2 class="font-semibold text-gray-800 px-5 pt-4 pb-2">By book and language</h2>
        @if ($byEdition->isEmpty())
            <p class="px-5 pb-5 text-sm text-gray-500">No AI calls in this period.</p>
        @else
            <div class="overflow-x-auto"><table class="w-full text-sm min-w-[40rem]">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-2 font-medium">Book</th>
                        <th class="px-5 py-2 font-medium">Language</th>
                        <th class="px-5 py-2 font-medium text-right">Calls</th>
                        <th class="px-5 py-2 font-medium text-right">Reused</th>
                        <th class="px-5 py-2 font-medium text-right">Cost</th>
                        <th class="px-5 py-2 font-medium text-right">Per page</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($byEdition as $row)
                        @php($book = $books->get($row->book_id))
                        <tr wire:key="ed-{{ $row->book_id }}-{{ $row->translation_id }}">
                            <td class="px-5 py-2.5">
                                @if ($book)
                                    <a href="{{ route('admin.book', $book) }}" class="text-gray-900 hover:text-brand-700 hover:underline">{{ $book->title }}</a>
                                @else
                                    <span class="text-gray-400">{{ $row->book_id ? 'Deleted book' : 'Not tied to a book' }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-2.5 text-gray-600">{{ $editions->get($row->translation_id)?->language_name ?? ($row->translation_id ? 'Deleted edition' : 'Original') }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ number_format($row->calls) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-gray-500">{{ number_format($row->hits) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-medium">{{ $rand($row->cost) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-gray-500">{{ $book && $book->page_count ? $rand($row->cost / $book->page_count) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    {{-- Recent calls --}}
    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
        <h2 class="font-semibold text-gray-800 px-5 pt-4 pb-2">Latest calls</h2>
        @if ($recent->isEmpty())
            <p class="px-5 pb-5 text-sm text-gray-500">No AI calls in this period.</p>
        @else
            <div class="overflow-x-auto"><table class="w-full text-sm min-w-[40rem]">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-2 font-medium">When</th>
                        <th class="px-5 py-2 font-medium">Job</th>
                        <th class="px-5 py-2 font-medium">Book</th>
                        <th class="px-5 py-2 font-medium">Model</th>
                        <th class="px-5 py-2 font-medium text-right">Time</th>
                        <th class="px-5 py-2 font-medium text-right">Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($recent as $call)
                        <tr wire:key="call-{{ $call->id }}">
                            <td class="px-5 py-2 text-gray-500 whitespace-nowrap" title="{{ $call->at }}">{{ $call->at->diffForHumans() }}</td>
                            <td class="px-5 py-2 text-gray-900">{{ \App\Models\AiLedgerEntry::operationName($call->operation) }}</td>
                            <td class="px-5 py-2 text-gray-600">{{ $call->book?->title ?? '—' }}{{ $call->translation ? ' · '.$call->translation->language_name : '' }}</td>
                            <td class="px-5 py-2 text-gray-500">{{ $call->model }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-gray-500">{{ number_format($call->duration_ms / 1000, 1) }} s</td>
                            <td class="px-5 py-2 text-right tabular-nums">
                                @if ($call->cache_hit)
                                    <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">reused</span>
                                @else
                                    {{ $rand($call->cost_zar) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    <p class="text-xs text-gray-400">Costs are estimates from the providers' list prices (config/ai.php), converted at R{{ number_format($usdToZar, 2) }} per US dollar. Your OpenAI and ElevenLabs invoices are the final word.</p>
</div>
