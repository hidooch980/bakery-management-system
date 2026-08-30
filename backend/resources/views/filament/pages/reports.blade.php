<x-filament-panels::page>
    @php
        $financial = $this->financialRows();
        $production = $this->productionRows();
        $consumption = $this->consumptionRows();

        $income = (float) $financial->sum('income');
        $expense = (float) $financial->sum('expense');
        $profit = $income - $expense;
    @endphp

    {{-- ----------------------------------------------------- the filter --}}
    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
            <x-filament::icon icon="heroicon-m-calendar-days" class="h-4 w-4 text-gray-400" />
            <span class="text-sm tracking-wide text-gray-500 dark:text-gray-400">
                {{ $this->rangeLabel() }}
            </span>
        </div>
    </x-filament::section>

    {{-- One Alpine scope around both, or the tabs and the panels below
         would each track a "tab" the other never sees. --}}
    <div x-data="{ tab: 'money' }" class="space-y-6">
        <x-filament::tabs>
            <x-filament::tabs.item icon="heroicon-m-banknotes" alpine-active="tab === 'money'" x-on:click="tab = 'money'">
                مالی
            </x-filament::tabs.item>

            <x-filament::tabs.item icon="heroicon-m-cube" alpine-active="tab === 'production'" x-on:click="tab = 'production'">
                تولید
            </x-filament::tabs.item>

            <x-filament::tabs.item icon="heroicon-m-beaker" alpine-active="tab === 'consumption'" x-on:click="tab = 'consumption'">
                مصارف
            </x-filament::tabs.item>
        </x-filament::tabs>

        {{-- ------------------------------------------------------ money --}}
        <div x-show="tab === 'money'" x-cloak class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-bakery.figure
                    label="جمع درآمد"
                    :value="$this->money($income)"
                    icon="heroicon-m-arrow-trending-up"
                    tone="success"
                />

                <x-bakery.figure
                    label="جمع هزینه"
                    :value="$this->money($expense)"
                    icon="heroicon-m-arrow-trending-down"
                    tone="danger"
                />

                <x-bakery.figure
                    label="سود"
                    :value="$this->money($profit)"
                    icon="heroicon-m-scale"
                    :tone="$profit >= 0 ? 'success' : 'danger'"
                    :caption="$income > 0 ? number_format($profit / $income * 100, 1).'٪ حاشیه سود' : null"
                />
            </div>

            <x-filament::section>
                <x-slot name="heading">درآمد و هزینه</x-slot>
                <x-slot name="description">همه مبالغ به {{ $this->currencyLabel() }}</x-slot>

                <x-bakery.report-table
                    :columns="['بازه', 'درآمد', 'نان', 'آرد', 'هزینه', 'حقوق', 'سود']"
                    :rows="$financial->count()"
                    empty="در این بازه رکوردی ثبت نشده است."
                >
                    @foreach ($financial as $row)
                        <tr class="border-b border-gray-50 transition hover:bg-gray-50/70 dark:border-white/5 dark:hover:bg-white/5">
                            <td class="whitespace-nowrap py-2.5 pe-3 font-medium">{{ $row['label'] }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ $row['income_formatted'] }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-500 dark:text-gray-400">{{ $this->money($row['income_bread']) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-500 dark:text-gray-400">{{ $this->money($row['income_flour']) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ $row['expense_formatted'] }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-500 dark:text-gray-400">{{ $this->money($row['expense_salaries']) }}</td>
                            <td @class([
                                'py-2.5 pe-3 font-semibold tabular-nums',
                                'text-success-600 dark:text-success-400' => $row['profit'] >= 0,
                                'text-danger-600 dark:text-danger-400' => $row['profit'] < 0,
                            ])>{{ $row['profit_formatted'] }}</td>
                        </tr>
                    @endforeach

                    <x-slot name="footer">
                        <td class="py-3 pe-3">جمع</td>
                        <td class="py-3 pe-3 tabular-nums">{{ $this->money($income) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ $this->money((float) $financial->sum('income_bread')) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ $this->money((float) $financial->sum('income_flour')) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ $this->money($expense) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ $this->money((float) $financial->sum('expense_salaries')) }}</td>
                        <td @class([
                            'py-3 pe-3 tabular-nums',
                            'text-success-600 dark:text-success-400' => $profit >= 0,
                            'text-danger-600 dark:text-danger-400' => $profit < 0,
                        ])>{{ $this->money($profit) }}</td>
                    </x-slot>
                </x-bakery.report-table>
            </x-filament::section>
        </div>

        {{-- ------------------------------------------------- production --}}
        <div x-show="tab === 'production'" x-cloak class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-bakery.figure
                    label="کیسه خمیرگیری‌شده"
                    :value="number_format((float) $production->sum('bags_kneaded'), 1).' کیسه'"
                    icon="heroicon-m-archive-box"
                    tone="info"
                />

                <x-bakery.figure
                    label="نان فروخته‌شده"
                    :value="number_format((int) $production->sum('bread_sold')).' نان'"
                    icon="heroicon-m-shopping-bag"
                    tone="info"
                />

                <x-bakery.figure
                    label="مبلغ فروش"
                    :value="$this->money((float) $production->sum('sales_amount'))"
                    icon="heroicon-m-banknotes"
                    tone="success"
                />
            </div>

            <x-filament::section>
                <x-slot name="heading">خمیرگیری، چانه و فروش</x-slot>

                <x-bakery.report-table
                    :columns="['بازه', 'کیسه', 'چانه عادی', 'نانینو', 'وزن چانه (کیلو)', 'نان فروخته‌شده', 'مبلغ فروش']"
                    :rows="$production->count()"
                    empty="در این بازه تولیدی ثبت نشده است."
                >
                    @foreach ($production as $row)
                        <tr class="border-b border-gray-50 transition hover:bg-gray-50/70 dark:border-white/5 dark:hover:bg-white/5">
                            <td class="whitespace-nowrap py-2.5 pe-3 font-medium">{{ $row['label'] }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['bags_kneaded'], 1) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['normal_chane_count']) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['nanino_chane_count']) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-500 dark:text-gray-400">
                                {{ number_format($row['normal_weight_kg'] + $row['nanino_weight_kg'], 1) }}
                            </td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['bread_sold']) }}</td>
                            <td class="py-2.5 pe-3 font-semibold tabular-nums">{{ $row['sales_amount_formatted'] }}</td>
                        </tr>
                    @endforeach

                    <x-slot name="footer">
                        <td class="py-3 pe-3">جمع</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $production->sum('bags_kneaded'), 1) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((int) $production->sum('normal_chane_count')) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((int) $production->sum('nanino_chane_count')) }}</td>
                        <td class="py-3 pe-3 tabular-nums">
                            {{ number_format((float) $production->sum('normal_weight_kg') + (float) $production->sum('nanino_weight_kg'), 1) }}
                        </td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((int) $production->sum('bread_sold')) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ $this->money((float) $production->sum('sales_amount')) }}</td>
                    </x-slot>
                </x-bakery.report-table>
            </x-filament::section>
        </div>

        {{-- ------------------------------------------------ consumption --}}
        <div x-show="tab === 'consumption'" x-cloak class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-bakery.figure
                    label="آرد مصرف‌شده"
                    :value="number_format((float) $consumption->sum('flour_used_kg'), 1).' کیلو'"
                    icon="heroicon-m-fire"
                    tone="warning"
                    caption="خمیرگیری و پاششی"
                />

                <x-bakery.figure
                    label="آرد فروخته‌شده"
                    :value="number_format((float) $consumption->sum('flour_sold_kg'), 1).' کیلو'"
                    icon="heroicon-m-truck"
                    tone="gray"
                    caption="نان نشده — از سهمیه کم نمی‌شود"
                />

                <x-bakery.figure
                    label="نمک و خمیرمایه"
                    :value="number_format((float) $consumption->sum('salt_kg'), 1).' + '.number_format((float) $consumption->sum('yeast_dry_kg'), 1).' کیلو'"
                    icon="heroicon-m-sparkles"
                    tone="info"
                />
            </div>

            <x-filament::section>
                <x-slot name="heading">مصرف آرد، نمک و خمیرمایه</x-slot>
                <x-slot name="description">
                    آرد فقط دو جور مصرف می‌شود: خمیرگیری و پاششی. آردی که فروخته یا
                    امانی داده شده جدا گزارش می‌شود، چون نان نشده است.
                </x-slot>

                <x-bakery.report-table
                    :columns="['بازه', 'کیسه', 'آرد خمیرگیری', 'آرد پاششی', 'جمع مصرف', 'آرد فروخته‌شده', 'نمک', 'خمیرمایه']"
                    :rows="$consumption->count()"
                    empty="در این بازه مصرفی ثبت نشده است."
                >
                    @foreach ($consumption as $row)
                        <tr class="border-b border-gray-50 transition hover:bg-gray-50/70 dark:border-white/5 dark:hover:bg-white/5">
                            <td class="whitespace-nowrap py-2.5 pe-3 font-medium">{{ $row['label'] }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['bags_kneaded'], 1) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['flour_production_kg'], 1) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums">{{ number_format($row['flour_spray_kg'], 1) }}</td>
                            <td class="py-2.5 pe-3 font-semibold tabular-nums">{{ number_format($row['flour_used_kg'], 1) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-400 dark:text-gray-500">{{ number_format($row['flour_sold_kg'], 1) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['salt_kg'], 2) }}</td>
                            <td class="py-2.5 pe-3 tabular-nums text-gray-500 dark:text-gray-400">
                                {{ number_format($row['yeast_dry_kg'], 2) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot name="footer">
                        <td class="py-3 pe-3">جمع</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $consumption->sum('bags_kneaded'), 1) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $consumption->sum('flour_production_kg'), 1) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $consumption->sum('flour_spray_kg'), 1) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $consumption->sum('flour_used_kg'), 1) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $consumption->sum('flour_sold_kg'), 1) }}</td>
                        <td class="py-3 pe-3 tabular-nums">{{ number_format((float) $consumption->sum('salt_kg'), 2) }}</td>
                        <td class="py-3 pe-3 tabular-nums">
                            {{ number_format((float) $consumption->sum('yeast_dry_kg'), 2) }}
                        </td>
                    </x-slot>
                </x-bakery.report-table>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
