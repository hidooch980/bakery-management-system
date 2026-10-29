<x-filament-panels::page>
    <label>ماه شمسی (مانند ۱۴۰۵/۰۸) <input wire:model.live.blur="month" /></label>
    @php $report = $this->report(); @endphp
    @error('month') <p role="alert">{{ $message }}</p> @enderror
    @if($report)
        <p>{{ $report['month_label'] }} — {{ $report['from_label'] }} تا {{ $report['to_label'] }}</p>
        <p>{{ $report['note'] }}</p>
        <x-filament::section heading="سهمیهٔ قابل دریافت — لیتر">
            <p>مانده از ماه قبل: {{ \App\Support\Qty::format($report['quota_opening_litres'], 2) }} — {{ $report['quota_opening_source'] }}</p>
            <p>سهمیه ماه: {{ $report['quota_recorded'] ? \App\Support\Qty::format($report['quota_litres'], 2) : 'ثبت نشده است' }}</p>
            <p>تحویل این ماه: {{ \App\Support\Qty::format($report['delivered_litres'], 2) }}</p>
            <p><strong>ماندهٔ قابل دریافت؛ انتقال به ماه بعد: {{ \App\Support\Qty::format($report['quota_closing_litres'], 2) }}</strong></p>
        </x-filament::section>
        <x-filament::section heading="گردش تخمینی باک — لیتر">
            <p>مانده باک از قبل: {{ \App\Support\Qty::format($report['tank_opening_litres'], 2) }}</p>
            <p>ورودی این ماه: {{ \App\Support\Qty::format($report['delivered_litres'], 2) }}</p>
            <p>مصرف تخمینی پخت: {{ \App\Support\Qty::format($report['consumed_litres'], 2) }}</p>
            <p><strong>ماندهٔ تخمینی باک؛ انتقال به ماه بعد: {{ \App\Support\Qty::format($report['tank_closing_litres'], 2) }}</strong></p>
        </x-filament::section>
        @if($report['uses_default_rate']) <p>برای بخشی از خمیرها نرخ تاریخی ثبت نشده؛ نرخ پیش‌فرض فعلی در برآورد استفاده شده است.</p> @endif
        @if($report['quota_records_count'] > 1) <p>بیش از یک سهمیه برای این ماه ثبت شده؛ جمع رکوردها نمایش داده می‌شود.</p> @endif
        <div style="overflow-x:auto">
            <table style="width:100%;text-align:right"><thead><tr><th>تاریخ</th><th>گردش</th><th>لیتر</th><th>جزئیات</th><th>ثبت‌کننده</th></tr></thead><tbody>
                @forelse($report['lines'] as $line)
                    <tr><td>{{ $line['date_label'] }}</td><td>{{ $line['label'] }} #{{ $line['id'] }}</td><td>{{ \App\Support\Qty::format($line['litres'], 2) }}</td><td>{{ $line['detail'] }}</td><td>{{ $line['user'] }}</td></tr>
                @empty <tr><td colspan="5">در این ماه گردشی ثبت نشده است.</td></tr> @endforelse
            </tbody></table>
        </div>
        @if($report['lines_truncated']) <p>۲۰۰ ردیف آخر نمایش داده شده؛ جمع‌ها شامل تمام گردش ماه هستند.</p> @endif
    @endif
</x-filament-panels::page>
