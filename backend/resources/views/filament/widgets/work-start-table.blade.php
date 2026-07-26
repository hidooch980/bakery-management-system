@php
    // The @php(...) shorthand directive compiled without a closing tag in
    // this Filament/Livewire setup, silently corrupting the rest of the
    // file into invalid PHP — hence the explicit block form here.
    $board = $this->getBoard();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            شروع کار امروز — {{ $board['date_display'] }}
        </x-slot>

        @if ($board['is_holiday'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                امروز تعطیل است؛ مهلتی برای شروع کار در نظر گرفته نمی‌شود.
            </p>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($board['items'] as $item)
                @php
                    $tone = $item['started']
                        ? ($item['is_late'] ? 'danger' : 'success')
                        : ($item['overdue'] ? 'danger' : 'gray');

                    $classes = match ($tone) {
                        'success' => 'border-success-500/40 bg-success-50/60 dark:bg-success-500/10',
                        'danger' => 'border-danger-500/40 bg-danger-50/60 dark:bg-danger-500/10',
                        default => 'border-gray-200 dark:border-white/10',
                    };

                    // Built as a single string rather than nested @if blocks:
                    // Livewire's Blade compiler wraps @if/@endif pairs with
                    // HTML markers for its diffing, and a nested @if sitting
                    // right before the parent's @elseif confuses that
                    // wrapping and produces a broken compiled file.
                    if ($item['started']) {
                        $status = 'ثبت شد: '.$item['started_at'];
                        if (! empty($item['started_by'])) {
                            $status .= ' — '.$item['started_by'];
                        }
                    } elseif ($item['is_holiday']) {
                        $status = 'تعطیل';
                    } elseif ($item['overdue']) {
                        $status = 'هنوز ثبت نشده و مهلت گذشته است';
                    } else {
                        $status = 'هنوز ثبت نشده — '.$item['minutes_remaining'].' دقیقه تا مهلت';
                    }
                @endphp

                <div class="rounded-xl border p-4 {{ $classes }}">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-bold">{{ $item['label'] }}</span>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            مهلت: {{ $item['deadline'] }}
                        </span>
                    </div>

                    <div class="mt-2 text-sm">
                        <span class="{{ match (true) {
                            $item['started'] => 'font-semibold',
                            $item['overdue'] => 'font-semibold text-danger-600 dark:text-danger-400',
                            default => 'text-gray-500 dark:text-gray-400',
                        } }}">
                            {{ $status }}
                        </span>
                    </div>

                    @if ($item['warning'])
                        <p class="mt-2 text-xs font-semibold text-danger-600 dark:text-danger-400">
                            {{ $item['warning'] }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
