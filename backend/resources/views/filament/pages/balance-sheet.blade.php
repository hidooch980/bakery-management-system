<x-filament-panels::page>
    @php
        $sheet = $this->sheet();
        $assets = $sheet['assets'] ?? [];
        $liabilities = $sheet['liabilities'] ?? [];
        $equity = (float) ($sheet['equity'] ?? 0);
        $missingAsset = $this->equityIsMissingAnAsset($sheet);
    @endphp

    {{-- ---------------------------------------------- the three totals --}}
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">دارایی‌ها</div>
            <div class="mt-1 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">
                {{ $sheet['asset_total_formatted'] }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">بدهی‌ها</div>
            <div class="mt-1 text-2xl font-extrabold text-rose-600 dark:text-rose-400">
                {{ $sheet['liability_total_formatted'] }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">سرمایه (دارایی منهای بدهی)</div>
            <div @class([
                'mt-1 text-2xl font-extrabold',
                'text-emerald-600 dark:text-emerald-400' => $equity >= 0,
                'text-rose-600 dark:text-rose-400' => $equity < 0,
            ])>
                {{ $sheet['equity_formatted'] }}
            </div>
        </x-filament::section>
    </div>

    {{--
        A large negative equity here is not what it looks like. The loan on
        this page bought a machine that the owner chose not to record as a
        fixed asset, so the debt is counted and the thing it paid for is
        not. Said before he reads the number, not after.
    --}}
    @if ($missingAsset)
        <x-filament::section>
            <div class="flex gap-3">
                <x-filament::icon icon="heroicon-o-information-circle"
                    class="h-5 w-5 shrink-0 text-amber-500" />
                <div class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                    <span class="font-bold">این عدد منفی، همهٔ داستان نیست.</span>
                    وامِ ثبت‌شده در این صفحه صرف خرید دستگاه نانوایی شده، ولی آن دستگاه
                    به‌عنوان «دارایی ثابت» ثبت نشده است — پس بدهی‌اش اینجا هست و خودش نیست.
                    اگر روزی ثبتش کنید، همین سرمایه به اندازهٔ ارزش دستگاه بالا می‌رود.
                </div>
            </div>
        </x-filament::section>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        {{-- --------------------------------------------------- assets --}}
        <x-filament::section>
            <x-slot name="heading">دارایی‌ها</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    @forelse ($assets as $row)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2">
                                <div class="font-medium">{{ $row['label'] }}</div>
                                @if (! empty($row['note']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</div>
                                @endif
                            </td>
                            <td class="py-2 text-left font-bold tabular-nums">
                                {{ $row['amount_formatted'] }}
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-3 text-gray-500">چیزی ثبت نشده.</td></tr>
                    @endforelse

                    <tr class="border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-2 font-bold">جمع دارایی‌ها</td>
                        <td class="py-2 text-left font-extrabold tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ $sheet['asset_total_formatted'] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>

        {{-- ---------------------------------------------- liabilities --}}
        <x-filament::section>
            <x-slot name="heading">بدهی‌ها</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    @forelse ($liabilities as $row)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2">
                                <div class="font-medium">{{ $row['label'] }}</div>
                                @if (! empty($row['note']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</div>
                                @endif
                            </td>
                            <td class="py-2 text-left font-bold tabular-nums">
                                {{ $row['amount_formatted'] }}
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-3 text-gray-500">بدهی‌ای ثبت نشده.</td></tr>
                    @endforelse

                    <tr class="border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-2 font-bold">جمع بدهی‌ها</td>
                        <td class="py-2 text-left font-extrabold tabular-nums text-rose-600 dark:text-rose-400">
                            {{ $sheet['liability_total_formatted'] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
