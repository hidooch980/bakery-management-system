<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}

        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            {{ $this->rangeLabel() }}
        </p>
    </x-filament::section>

    @php
        $financial = $this->financialRows();
        $production = $this->productionRows();
        $consumption = $this->consumptionRows();
    @endphp

    {{-- One Alpine scope around both, or the tabs and the panels below
         would each track a "tab" the other never sees. --}}
    <div x-data="{ tab: 'money' }" class="space-y-6">
        <x-filament::tabs>
            <x-filament::tabs.item alpine-active="tab === 'money'" x-on:click="tab = 'money'">
                مالی
            </x-filament::tabs.item>

            <x-filament::tabs.item alpine-active="tab === 'production'" x-on:click="tab = 'production'">
                تولید
            </x-filament::tabs.item>

            <x-filament::tabs.item alpine-active="tab === 'consumption'" x-on:click="tab = 'consumption'">
                مصارف
            </x-filament::tabs.item>
        </x-filament::tabs>

        {{-- ------------------------------------------------------ money --}}
        <div x-show="tab === 'money'" x-cloak>
            <x-filament::section>
                <x-slot name="heading">
                    درآمد، هزینه و سود — {{ $this->currencyLabel() }}
                </x-slot>

                <div class="grid gap-3 sm:grid-cols-3">
                    @php
                        $income = (float) $financial->sum('income');
                        $expense = (float) $financial->sum('expense');
                        $profit = $income - $expense;
                    @endphp

                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-xs text-gray-500 dark:text-gray-400">جمع درآمد</p>
                        <p class="text-lg font-bold text-success-600 dark:text-success-400">
                            {{ $this->money($income) }}
                        </p>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-xs text-gray-500 dark:text-gray-400">جمع هزینه</p>
                        <p class="text-lg font-bold text-danger-600 dark:text-danger-400">
                            {{ $this->money($expense) }}
                        </p>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-xs text-gray-500 dark:text-gray-400">سود</p>
                        <p @class([
                            'text-lg font-bold',
                            'text-success-600 dark:text-success-400' => $profit >= 0,
                            'text-danger-600 dark:text-danger-400' => $profit < 0,
                        ])>
                            {{ $this->money($profit) }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 text-start font-medium">بازه</th>
                                <th class="py-2 text-start font-medium">درآمد</th>
                                <th class="py-2 text-start font-medium">نان</th>
                                <th class="py-2 text-start font-medium">آرد</th>
                                <th class="py-2 text-start font-medium">هزینه</th>
                                <th class="py-2 text-start font-medium">حقوق</th>
                                <th class="py-2 text-start font-medium">سود</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($financial as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 font-medium">{{ $row['label'] }}</td>
                                    <td class="py-2">{{ $row['income_formatted'] }}</td>
                                    <td class="py-2">{{ $this->money($row['income_bread']) }}</td>
                                    <td class="py-2">{{ $this->money($row['income_flour']) }}</td>
                                    <td class="py-2">{{ $row['expense_formatted'] }}</td>
                                    <td class="py-2">{{ $this->money($row['expense_salaries']) }}</td>
                                    <td @class([
                                        'py-2 font-bold',
                                        'text-success-600 dark:text-success-400' => $row['profit'] >= 0,
                                        'text-danger-600 dark:text-danger-400' => $row['profit'] < 0,
                                    ])>{{ $row['profit_formatted'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-6 text-center text-gray-500">
                                        در این بازه رکوردی نیست.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- ------------------------------------------------- production --}}
        <div x-show="tab === 'production'" x-cloak>
            <x-filament::section heading="خمیرگیری، چانه و فروش">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 text-start font-medium">بازه</th>
                                <th class="py-2 text-start font-medium">کیسه</th>
                                <th class="py-2 text-start font-medium">چانه عادی</th>
                                <th class="py-2 text-start font-medium">نانینو</th>
                                <th class="py-2 text-start font-medium">وزن چانه (کیلو)</th>
                                <th class="py-2 text-start font-medium">نان فروخته‌شده</th>
                                <th class="py-2 text-start font-medium">مبلغ فروش</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($production as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 font-medium">{{ $row['label'] }}</td>
                                    <td class="py-2">{{ number_format($row['bags_kneaded'], 1) }}</td>
                                    <td class="py-2">{{ number_format($row['normal_chane_count']) }}</td>
                                    <td class="py-2">{{ number_format($row['nanino_chane_count']) }}</td>
                                    <td class="py-2">
                                        {{ number_format($row['normal_weight_kg'] + $row['nanino_weight_kg'], 1) }}
                                    </td>
                                    <td class="py-2">{{ number_format($row['bread_sold']) }}</td>
                                    <td class="py-2 font-bold">{{ $row['sales_amount_formatted'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-6 text-center text-gray-500">
                                        در این بازه تولیدی ثبت نشده.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- ------------------------------------------------ consumption --}}
        <div x-show="tab === 'consumption'" x-cloak>
            <x-filament::section heading="مصرف آرد، نمک و خمیرمایه (کیلوگرم)">
                <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                    آرد فقط دو جور مصرف می‌شود: خمیرگیری و پاششی. آردی که فروخته یا
                    امانی داده شده جدا گزارش می‌شود، چون نان نشده است.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 text-start font-medium">بازه</th>
                                <th class="py-2 text-start font-medium">کیسه</th>
                                <th class="py-2 text-start font-medium">آرد خمیرگیری</th>
                                <th class="py-2 text-start font-medium">آرد پاششی</th>
                                <th class="py-2 text-start font-medium">جمع مصرف</th>
                                <th class="py-2 text-start font-medium">آرد فروخته‌شده</th>
                                <th class="py-2 text-start font-medium">نمک</th>
                                <th class="py-2 text-start font-medium">خمیرمایه</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($consumption as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 font-medium">{{ $row['label'] }}</td>
                                    <td class="py-2">{{ number_format($row['bags_kneaded'], 1) }}</td>
                                    <td class="py-2">{{ number_format($row['flour_production_kg'], 1) }}</td>
                                    <td class="py-2">{{ number_format($row['flour_spray_kg'], 1) }}</td>
                                    <td class="py-2 font-bold">{{ number_format($row['flour_used_kg'], 1) }}</td>
                                    <td class="py-2 text-gray-500">
                                        {{ number_format($row['flour_sold_kg'], 1) }}
                                    </td>
                                    <td class="py-2">{{ number_format($row['salt_kg'], 2) }}</td>
                                    <td class="py-2">
                                        {{ number_format($row['yeast_dry_kg'] + $row['yeast_wet_kg'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-6 text-center text-gray-500">
                                        در این بازه مصرفی ثبت نشده.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
