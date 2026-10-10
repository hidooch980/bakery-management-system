<x-filament-panels::page>
    @php
        $statement = $this->statement();
        $current = \App\Support\PartnerStatement::headline($statement->current);
        $closing = \App\Support\PartnerStatement::headline($statement->closing);
        $last = $statement->lastActivity();
        $S = \App\Support\PartnerStatement::class;
        // رنگ‌ها با style نوشته شده‌اند، نه کلاس tailwind: پنل تم سفارشی
        // ندارد و کلاس‌هایی که فیلامنت خودش نساخته اعمال نمی‌شوند.
        $tone = fn (string $t) => match ($t) {
            'owed' => 'color:#1E6B44;background:rgba(46,158,107,.12);border:1px solid rgba(46,158,107,.35)',
            'owes' => 'color:#a12f40;background:rgba(209,73,91,.10);border:1px solid rgba(209,73,91,.35)',
            default => 'color:#4b4f55;background:rgba(110,114,120,.10);border:1px solid rgba(110,114,120,.30)',
        };
        $ink = fn (float $v) => $v > 0.001 ? 'color:#2E9E6B' : ($v < -0.001 ? 'color:#D1495B' : 'color:#6E7278');
        $badge = fn (string $kind) => match ($kind) {
            'lent' => 'success',
            'borrowed' => 'danger',
            default => 'info',
        };
    @endphp

    <style>
        .ps-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ps-sg { direction: ltr; unicode-bidi: isolate; display: inline-block; }
        .ps-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ps-table th { text-align: right; font-weight: 500; padding: .625rem .75rem; border-bottom: 1px solid rgba(127,127,127,.2); white-space: nowrap; }
        .ps-table td { padding: .625rem .75rem; border-bottom: 1px solid rgba(127,127,127,.1); vertical-align: top; }
        .ps-total td { font-weight: 700; background: rgba(127,127,127,.06); }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header-actions, .fi-header .fi-ac, .ps-noprint, .fi-breadcrumbs { display: none !important; }
            .fi-main { padding: 0 !important; max-width: none !important; }
            .fi-layout, .fi-main-ctn { margin: 0 !important; }
            body { background: #fff !important; }
        }
    </style>

    {{-- سرخط: ماندهٔ امروز --}}
    <div style="{{ $tone($current['tone']) }};border-radius:14px;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div>
            <div style="font-size:.875rem">ماندهٔ فعلی</div>
            <div style="font-size:2rem;font-weight:800;line-height:1.3" class="ps-num" data-headline>{{ $current['label'] }}</div>
        </div>
        <div style="font-size:.875rem;text-align:left;line-height:1.8">
            تلفن: <span class="ps-num" dir="ltr">{{ $this->record->phone ?: 'ثبت نشده' }}</span><br>
            آخرین گردش: <span class="ps-num">{{ $last ? \App\Support\AppCalendar::date($last) : '—' }}</span>
        </div>
    </div>

    {{-- فیلتر تاریخ --}}
    <x-filament::section class="ps-noprint">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <label style="display:block">
                <div style="font-size:.75rem;margin-bottom:4px">از تاریخ</div>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="from" placeholder="1405/05/01" dir="ltr" />
                </x-filament::input.wrapper>
            </label>
            <label style="display:block">
                <div style="font-size:.75rem;margin-bottom:4px">تا تاریخ</div>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="to" placeholder="1405/07/30" dir="ltr" />
                </x-filament::input.wrapper>
            </label>
            <x-filament::button color="gray" wire:click="$refresh">اعمال فیلتر</x-filament::button>
            @if ($from || $to)
                <x-filament::button color="gray" outlined wire:click="clearFilter">همهٔ تاریخ‌ها</x-filament::button>
            @endif
            <span style="font-size:.75rem;opacity:.7">همه مقادیر به کیسه</span>
        </div>
    </x-filament::section>

    @if ($from || $to)
        <div style="font-size:.875rem">
            بازه: {{ $statement->from ? \App\Support\AppCalendar::date($statement->from) : 'از ابتدا' }}
            تا {{ $statement->to ? \App\Support\AppCalendar::date($statement->to) : 'امروز' }}
        </div>
    @endif

    {{-- گردش ریز --}}
    <x-filament::section>
        <div style="overflow-x:auto;margin:-.5rem">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>نوع</th>
                        <th>کیسه</th>
                        <th>شرح</th>
                        <th>ثبت‌کننده</th>
                        <th>مانده پس از ردیف</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-num">—</td>
                        <td><x-filament::badge color="gray">ماندهٔ اول دوره</x-filament::badge></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td class="ps-num" style="font-weight:700;{{ $ink($statement->opening) }}"><span class="ps-sg">{{ $S::signed($statement->opening) }}</span></td>
                    </tr>
                    @forelse ($statement->rows as $row)
                        <tr data-ledger-row>
                            <td class="ps-num">
                                {{ \App\Support\AppCalendar::date($row['date']) }}
                                @if ($row['approximate'])
                                    <div style="color:#C2691C;font-size:.75rem">⚠ تاریخ تقریبی</div>
                                @endif
                            </td>
                            <td><x-filament::badge :color="$badge($row['kind'])">{{ $row['label'] }}</x-filament::badge></td>
                            <td class="ps-num" style="font-weight:700"><span class="ps-sg">{{ $S::signed($row['delta']) }}</span></td>
                            <td style="max-width:28rem;font-size:.8125rem;opacity:.85">{{ $row['note'] }}</td>
                            <td style="font-size:.8125rem">{{ $row['user'] ?? '—' }}</td>
                            <td class="ps-num" style="font-weight:700;{{ $ink($row['balance']) }}"><span class="ps-sg">{{ $S::signed($row['balance']) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center;padding:2rem;opacity:.7">در این بازه گردشی نیست.</td>
                        </tr>
                    @endforelse
                    <tr class="ps-total">
                        <td colspan="2">جمع دوره</td>
                        <td colspan="3" class="ps-num">
                            دادیم {{ $S::bags($statement->total($S::LENT)) }}
                            • گرفتیم {{ $S::bags($statement->total($S::BORROWED)) }}
                            • برگشت‌ها {{ $S::bags($statement->totalReturns()) }}
                            <span style="font-weight:400;opacity:.75">(پس گرفتیم {{ $S::bags($statement->total($S::RETURNED_TO_US)) }}، پس دادیم {{ $S::bags($statement->total($S::RETURNED_BY_US)) }})</span>
                        </td>
                        <td class="ps-num" style="{{ $ink($statement->closing) }}">{{ $closing['label'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div style="font-size:.75rem;opacity:.7">مانده مثبت = طلب ما (آرد ما نزد همکار) • مانده منفی = بدهی ما</div>
</x-filament-panels::page>
