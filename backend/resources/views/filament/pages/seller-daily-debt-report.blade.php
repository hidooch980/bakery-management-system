<x-filament-panels::page>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
        <label>فروشنده
            <select wire:model.live="sellerId">
                <option value="">انتخاب فروشنده</option>
                @foreach($this->sellers() as $seller)
                    <option value="{{ $seller->id }}">{{ $seller->name }}</option>
                @endforeach
            </select>
        </label>
        <label>از تاریخ (شمسی یا میلادی)<input wire:model.live.blur="from" /></label>
        <label>تا تاریخ<input wire:model.live.blur="to" /></label>
    </div>
    @php $report = $this->report(); @endphp
    @error('from') <p role="alert">{{ $message }}</p> @enderror
    @if($report)
        <x-filament::section heading="ماندهٔ حساب — همهٔ تاریخ‌ها">
            <p>پول نقد نزد فروشنده: {{ $report['summary']['cash_formatted'] }}</p>
            <p>کسری نان: {{ $report['summary']['shortfall_formatted'] }}</p>
            <p>اختلاف صندوق: {{ $report['summary']['difference_formatted'] }}</p>
            <p>جمع قابل تسویه: {{ $report['summary']['total_formatted'] }}</p>
            <p>علی‌الحساب باقیمانده از پرداخت‌های قبلی: {{ $report['summary']['on_account_formatted'] }}</p>
            <p><strong>ماندهٔ بدهی فروشنده: {{ $report['summary']['still_owed_formatted'] }}</strong></p>
            <p>نسیهٔ مشتری‌ها، جدا از بدهی فروشنده: {{ $report['summary']['credit_formatted'] }}</p>
        </x-filament::section>
        <p>{{ $report['card_source'] }}</p>
        <p>{{ $report['scope_note'] }}</p>
        <p>{{ $report['quota_note'] }}</p>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;text-align:right">
                <thead><tr><th>تاریخ</th><th>آرد روزانه نانوایی</th><th>معادل کارتخوان سهمیه</th><th>چانه تولید نانوایی</th><th>کارتخوان کل نانوایی</th><th>کارتخوان فروشنده</th><th>مبلغ کارتخوان فروشنده</th><th>تحویل وجه این روز</th></tr></thead>
                <tbody>
                    @foreach($report['days'] as $day)
                        <tr>
                            <td>{{ $day['date_label'] }}
                                <details><summary>ریز فروش روز</summary>
                                    @forelse($day['lines'] as $line)
                                        <p>#{{ $line['id'] }} — {{ $line['payment_label'] }} — {{ $line['bread_count'] }} نان — {{ $line['amount_formatted'] }}</p>
                                    @empty <p>فروش ثبت نشده است.</p> @endforelse
                                    <p>منزل {{ $day['home_bread_count'] }} | نسیه {{ $day['credit_bread_count'] }} | ضایعات {{ $day['waste_bread_count'] }}</p>
                                </details>
                            </td>
                            <td>{{ $day['quota_bags'] === null ? $day['quota_status'] : $day['quota_bags'].' کیسه' }}</td>
                            <td>{{ $day['quota_card_target'] === null ? 'نامشخص' : $day['quota_card_target'].' نان' }}</td>
                            <td>{{ $day['bakery_chane_count'] }}</td><td>{{ $day['bakery_card_bread_count'] }}</td>
                            <td>{{ $day['seller_card_bread_count'] }}</td><td>{{ $day['seller_card_amount_formatted'] }}</td>
                            <td>{{ $day['handed_over_formatted'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-filament::section heading="ریز بدهی‌های باز — همهٔ تاریخ‌ها">
            @forelse($report['open_days'] as $day)
                <details><summary>{{ $day['date_label'] }} — {{ $day['total_formatted'] }}</summary>
                    @foreach($day['lines'] as $line)
                        <p>ثبت #{{ $line['id'] }} | چانه #{{ $line['chane_entry_id'] }} | {{ $line['payment_label'] }} | {{ $line['bread_count'] }} نان | نقد {{ $line['cash_formatted'] }} | کسری {{ $line['shortfall_formatted'] }} | اختلاف {{ $line['difference_formatted'] }} | بدهی {{ $line['open_debt_formatted'] }}</p>
                    @endforeach
                    <p>نسیهٔ مشتری‌ها: {{ $day['credit_formatted'] }}</p>
                </details>
            @empty <p>بدهی بازی ثبت نشده است.</p> @endforelse
        </x-filament::section>
        <x-filament::section heading="پرداخت‌ها در بازهٔ انتخابی">
            <p>{{ $report['handover_note'] }}</p>
            @foreach($report['handovers'] as $payment)
                <p>{{ $payment['date_label'] }} — {{ $payment['amount_formatted'] }} {{ $payment['is_reversed'] ? '(اصلاح و برگشت داده شده)' : '' }}</p>
            @endforeach
            @if($report['handovers_truncated']) <p>فقط ۱۰۰ پرداخت آخر نمایش داده شده؛ بازه را کوتاه‌تر کنید.</p> @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
