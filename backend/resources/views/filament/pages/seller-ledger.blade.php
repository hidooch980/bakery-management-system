<x-filament-panels::page>
    <div class="flex flex-wrap gap-3 items-center">
        <label class="text-sm">فروشنده
            <select wire:model.live="sellerId" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                <option value="">انتخاب فروشنده</option>
                @foreach ($this->sellers() as $seller)
                    <option value="{{ $seller->id }}">{{ $seller->name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @php($l = $this->ledger())
    @if ($l)
        @if ($l['warning'])
            <div class="rounded-xl p-4 text-sm bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                <p class="font-semibold">⚠️ مغایرت</p>
                <p>{{ $l['warning'] }}</p>
                <p class="mt-1 text-xs">برای اصلاح حساب‌های قدیمی دستور <span dir="ltr">sellers:repair-ledger</span> روی سرور اجرا می‌شود (اول با <span dir="ltr">--dry-run</span>).</p>
            </div>
        @else
            <div class="rounded-xl p-3 text-sm bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400">
                ✓ ماندهٔ حساب با دفتر یکی است.
            </div>
        @endif

        <div class="grid gap-3 sm:grid-cols-4">
            <x-filament::section><p class="text-xs text-gray-500">جمع بدهی‌ها</p><p class="text-lg font-bold">{{ $l['debts_total_formatted'] }}</p></x-filament::section>
            <x-filament::section><p class="text-xs text-gray-500">جمع تسویه‌ها</p><p class="text-lg font-bold">{{ $l['paid_total_formatted'] }}</p></x-filament::section>
            <x-filament::section><p class="text-xs text-gray-500">پیش‌پرداخت</p><p class="text-lg font-bold">{{ $l['prepaid_formatted'] }}</p></x-filament::section>
            <x-filament::section><p class="text-xs text-gray-500">ماندهٔ بدهی</p><p class="text-lg font-bold text-danger-600">{{ $l['balance_formatted'] }}</p></x-filament::section>
        </div>
        <p class="text-xs text-gray-500">نسیهٔ مدارس و مشتری‌ها جدا از بدهی فروشنده است: {{ $l['customer_credit_formatted'] }}</p>

        <x-filament::section heading="گردش حساب (جدیدترین بالا)">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right">
                    <thead>
                        <tr class="border-b dark:border-gray-700 text-gray-500">
                            <th class="p-2">تاریخ</th><th class="p-2">شرح</th><th class="p-2">بدهکار</th><th class="p-2">بستانکار (تسویه)</th><th class="p-2">مانده</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($l['entries'] as $e)
                            <tr class="border-b dark:border-gray-800">
                                <td class="p-2 whitespace-nowrap">{{ $e['on_label'] }}</td>
                                <td class="p-2">{{ $e['label'] }}</td>
                                <td class="p-2 text-danger-600">{{ $e['debit_formatted'] }}</td>
                                <td class="p-2 text-success-600">{{ $e['credit_formatted'] }}</td>
                                <td class="p-2 font-semibold">{{ $e['balance_formatted'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-4 text-center text-gray-500">گردشی ثبت نشده است.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
