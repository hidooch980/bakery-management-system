@props([
    'label',
    'value',
    'icon' => null,
    'tone' => 'gray',
    'caption' => null,
])

@php
    $tones = [
        'success' => 'text-success-600 dark:text-success-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'info' => 'text-primary-600 dark:text-primary-400',
        'gray' => 'text-gray-700 dark:text-gray-300',
    ];

    $rings = [
        'success' => 'bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400',
        'danger' => 'bg-danger-50 text-danger-600 dark:bg-danger-400/10 dark:text-danger-400',
        'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-400/10 dark:text-warning-400',
        'info' => 'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400',
        'gray' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
    ];
@endphp

{{-- One headline number, stated plainly. Restrained on purpose: the tables
     below carry the detail, and a card that shouts competes with them. --}}
<div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium tracking-wide text-gray-500 dark:text-gray-400">
                {{ $label }}
            </p>

            <p class="mt-1.5 truncate text-xl font-bold tabular-nums {{ $tones[$tone] ?? $tones['gray'] }}">
                {{ $value }}
            </p>

            @if ($caption)
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $caption }}</p>
            @endif
        </div>

        @if ($icon)
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $rings[$tone] ?? $rings['gray'] }}">
                <x-filament::icon :icon="$icon" class="h-5 w-5" />
            </span>
        @endif
    </div>
</div>
