<x-filament-panels::page>
    @php
        $open = $this->getOpenIssues();
        $answered = $this->getAnsweredIssues();
    @endphp

    @if ($open->isEmpty())
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-10 text-center">
                <x-filament::icon
                    icon="heroicon-o-check-badge"
                    class="h-12 w-12 text-success-500"
                />
                <p class="text-lg font-bold">همه چیز مرتب است</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($answered->isEmpty())
                        در موجودی، تولید، سهمیه و حساب فروشندگان مغایرتی پیدا نشد.
                    @else
                        مورد بازی نمانده — {{ $answered->count() }} مورد پایین‌تر، تصمیمشان گرفته شده است.
                    @endif
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-4">
            @foreach ($open as $issue)
                @php($grew = $this->growthFor($issue))

                <x-filament::section>
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <x-filament::icon
                                :icon="$issue->icon()"
                                @class([
                                    'h-6 w-6 shrink-0',
                                    'text-danger-500' => $issue->severity === 'critical',
                                    'text-warning-500' => $issue->severity === 'warning',
                                    'text-info-500' => $issue->severity === 'info',
                                ])
                            />

                            <span class="text-base font-bold">{{ $issue->title }}</span>

                            <x-filament::badge :color="$issue->color()">
                                {{ $issue->severityLabel() }}
                            </x-filament::badge>

                            @if ($grew)
                                <x-filament::badge color="danger" icon="heroicon-m-arrow-trending-up">
                                    {{ number_format($grew) }}٪ بدتر از زمانی که پاسخ دادید
                                </x-filament::badge>
                            @endif
                        </div>

                        <p class="text-sm">{{ $issue->detail }}</p>

                        <div class="grid gap-2 text-sm sm:grid-cols-2">
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                <span class="font-semibold">علت احتمالی:</span>
                                {{ $issue->cause }}
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                <span class="font-semibold">راه‌حل:</span>
                                {{ $issue->suggestion }}
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($issue->url)
                                <x-filament::link :href="$issue->url" size="sm">
                                    {{ $issue->urlLabel ?? 'مشاهده' }}
                                </x-filament::link>
                            @endif

                            @if ($issue->isAutoFixable())
                                <span class="text-xs text-warning-600 dark:text-warning-400">
                                    قابل اصلاح خودکار: {{ $issue->autoFixLabel }}
                                </span>
                            @endif

                            <div class="ms-auto">
                                {{ ($this->acknowledgeAction)(['key' => $issue->key]) }}
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif

    @if ($answered->isNotEmpty())
        <x-filament::section
            collapsible
            collapsed
            icon="heroicon-o-hand-thumb-up"
            :heading="'تصمیم گرفته‌شده — '.$answered->count().' مورد'"
            description="اینها همچنان در داده‌ها هستند و شمرده نمی‌شوند، چون تصمیمشان گرفته شده. اگر بزرگ‌تر شوند خودشان به فهرست بالا برمی‌گردند."
        >
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($answered as $issue)
                    @php($answer = $this->answerFor($issue))

                    <div class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">
                                {{ $issue->title }}
                            </span>
                            <x-filament::badge :color="$issue->color()" size="sm">
                                {{ $issue->severityLabel() }}
                            </x-filament::badge>
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $issue->detail }}</p>

                        @if ($answer?->note)
                            <p class="rounded-lg bg-gray-50 p-2 text-xs dark:bg-white/5">
                                «{{ $answer->note }}»
                            </p>
                        @endif

                        <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>
                                {{ $answer?->acknowledgedBy?->name ?? 'نامشخص' }} —
                                {{ \App\Support\AppCalendar::date($answer?->created_at) }}
                            </span>

                            <div class="ms-auto">
                                {{ ($this->reopenAction)(['key' => $issue->key]) }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
