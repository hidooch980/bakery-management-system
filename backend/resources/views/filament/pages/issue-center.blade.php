<x-filament-panels::page>
    @php($issues = $this->getIssues())

    @if ($issues->isEmpty())
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-10 text-center">
                <x-filament::icon
                    icon="heroicon-o-check-badge"
                    class="h-12 w-12 text-success-500"
                />
                <p class="text-lg font-bold">همه چیز مرتب است</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    در موجودی، تولید، سهمیه و حساب فروشندگان مغایرتی پیدا نشد.
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-4">
            @foreach ($issues as $issue)
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
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
