<x-filament-panels::page>
    @php($settings = \App\Models\BaleSetting::current())

    @if ($settings->last_tested_at)
        <div @class([
            'rounded-xl p-4 text-sm',
            'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $settings->last_test_succeeded,
            'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => ! $settings->last_test_succeeded,
        ])>
            <p class="font-semibold">
                @if ($settings->last_test_succeeded)
                    آخرین ارسال آزمایشی موفق بود
                @else
                    آخرین ارسال آزمایشی ناموفق بود
                @endif
                — {{ \App\Support\AppCalendar::dateTime($settings->last_tested_at) }}
            </p>

            @if (! $settings->last_test_succeeded && $settings->last_test_error)
                <p class="mt-2 font-mono text-xs break-words">
                    {{ \Illuminate\Support\Str::limit($settings->last_test_error, 400) }}
                </p>
            @endif
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end gap-3">
            @foreach ($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>
</x-filament-panels::page>
