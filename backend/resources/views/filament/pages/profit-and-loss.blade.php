<x-filament-panels::page>
    @php
        $s = $this->statement();
        $income = $s['income'];
        $profit = (float) $s['profit'];
        $incomeTotal = (float) $s['income_total'];
        $share = fn (float $part) => $incomeTotal > 0 ? round($part / $incomeTotal * 100) : 0;
    @endphp

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
            <x-filament::icon icon="heroicon-m-calendar-days" class="h-4 w-4 text-gray-400" />
            <span class="text-sm tracking-wide text-gray-500 dark:text-gray-400">
                {{ $this->rangeLabel() }}
            </span>
        </div>
    </x-filament::section>

    {{-- The answer, before the working that leads to it. --}}
    <x-filament::section>
        <div class="text-sm text-gray-500 dark:text-gray-400">سود دوره</div>
        <div @class([
            'mt-1 text-3xl font-extrabold tabular-nums',
            'text-emerald-600 dark:text-emerald-400' => $profit >= 0,
            'text-rose-600 dark:text-rose-400' => $profit < 0,
        ])>
            {{ $this->money($profit) }}
        </div>
        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            درآمد منهای هرچه از حساب بیرون رفته — هزینه روزی می‌افتد که پول پرداخت می‌شود.
        </div>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2">
        {{-- ------------------------------------------------- income --}}
        <x-filament::section>
            <x-slot name="heading">درآمد</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2">فروش نان</td>
                        <td class="py-2 text-left tabular-nums">{{ $income['bread_formatted'] }}</td>
                        <td class="py-2 pr-3 text-left text-xs text-gray-400">{{ $share((float) $income['bread']) }}٪</td>
                    </tr>
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2">فروش آرد</td>
                        <td class="py-2 text-left tabular-nums">{{ $income['flour_formatted'] }}</td>
                        <td class="py-2 pr-3 text-left text-xs text-gray-400">{{ $share((float) $income['flour']) }}٪</td>
                    </tr>
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2">سایر درآمدها</td>
                        <td class="py-2 text-left tabular-nums">{{ $income['other_formatted'] }}</td>
                        <td class="py-2 pr-3 text-left text-xs text-gray-400">{{ $share((float) $income['other']) }}٪</td>
                    </tr>
                    <tr class="border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-2 font-bold">جمع درآمد</td>
                        <td class="py-2 text-left font-extrabold tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ $income['total_formatted'] }}
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>

        {{-- ------------------------------------------------ outgoings --}}
        <x-filament::section>
            <x-slot name="heading">هزینه‌ها</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    @foreach ($s['costs'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2">{{ $row['label'] }}</td>
                            <td class="py-2 text-left tabular-nums">{{ $this->money((float) $row['amount']) }}</td>
                            <td class="py-2 pr-3 text-left text-xs text-gray-400">{{ $share((float) $row['amount']) }}٪</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-2 font-bold">جمع هزینه‌ها</td>
                        <td class="py-2 text-left font-extrabold tabular-nums text-rose-600 dark:text-rose-400">
                            {{ $this->money((float) $s['expense_total']) }}
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>
    </div>

    {{--
        The accrual view, beside the headline rather than competing with
        it. It counts flour as it is baked instead of as it is bought, so
        the two disagree in any period where buying and baking do not line
        up — and a reader who does not know that reads the difference as an
        error. On 2026-08-16 that difference was 164,640,000 Rial.
    --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">نگاه دوم: بهای تمام‌شده</x-slot>

        <div class="mb-3 text-xs leading-6 text-gray-500 dark:text-gray-400">
            این نگاه آرد را روزی حساب می‌کند که پخت می‌شود، نه روزی که خریده می‌شود.
            پس در دوره‌ای که خرید و مصرف با هم نمی‌خوانند، عددش با سود بالا فرق دارد —
            و این تفاوت اشتباه نیست، دو سؤال متفاوت است.
        </div>

        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b border-gray-100 dark:border-white/5">
                    <td class="py-2">بهای تمام‌شدهٔ کالای فروخته‌شده</td>
                    <td class="py-2 text-left tabular-nums">{{ $this->money((float) $s['cogs']) }}</td>
                </tr>
                <tr>
                    <td class="py-2 font-bold">سود ناخالص</td>
                    <td class="py-2 text-left font-extrabold tabular-nums">
                        {{ $this->money((float) $s['gross_profit']) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
