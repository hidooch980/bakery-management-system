@props([
    'columns' => [],
    'rows' => 0,
    'empty' => 'رکوردی برای نمایش نیست.',
    'footer' => null,
])

{{-- A plain report table: hairline rules, figures in tabular numerals so
     the columns line up down the page, and a totals row that reads as the
     answer rather than as one more line of data. --}}
@if ($rows === 0)
    <div class="flex flex-col items-center gap-2 py-10 text-center">
        <x-filament::icon
            icon="heroicon-o-inbox"
            class="h-8 w-8 text-gray-300 dark:text-gray-600"
        />
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</p>
    </div>
@else
    <div class="-mx-2 overflow-x-auto px-2">
        <table class="w-full min-w-[40rem] text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10">
                    @foreach ($columns as $column)
                        <th class="whitespace-nowrap pb-2.5 pe-3 text-start text-xs font-semibold tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $column }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                {{ $slot }}
            </tbody>

            @if ($footer)
                <tfoot>
                    <tr class="border-t-2 border-gray-200 text-sm font-bold dark:border-white/10">
                        {{ $footer }}
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endif
