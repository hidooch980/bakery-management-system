@php($board = $this->getBoard())

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
                @endphp

                <div class="rounded-xl border p-4 {{ $classes }}">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-bold">{{ $item['label'] }}</span>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            مهلت: {{ $item['deadline'] }}
                        </span>
                    </div>

                    <div class="mt-2 text-sm">
                        @if ($item['started'])
                            <span class="font-semibold">
                                ثبت شد: {{ $item['started_at'] }}
                            </span>

                            @if ($item['started_by'])
                                <span class="text-gray-500 dark:text-gray-400">
                                    — {{ $item['started_by'] }}
                                </span>
                            @endif
                        @elseif ($item['is_holiday'])
                            <span class="text-gray-500 dark:text-gray-400">تعطیل</span>
                        @elseif ($item['overdue'])
                            <span class="font-semibold text-danger-600 dark:text-danger-400">
                                هنوز ثبت نشده و مهلت گذشته است
                            </span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">
                                هنوز ثبت نشده — {{ $item['minutes_remaining'] }} دقیقه تا مهلت
                            </span>
                        @endif
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
