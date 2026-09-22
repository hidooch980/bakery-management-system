{{--
    A month's staff costs, on paper.

    The print rules are the point of the page. Printing the panel as it
    stands puts the sidebar, the topbar and every button on the sheet and
    squeezes the table into a column; what a person wants to hold is the
    table and the month it belongs to.

    Written as a style block rather than a build step, the same reason
    the ground stylesheet is one: the panel ships no compiled CSS of ours.
--}}
<x-filament-panels::page>
    <style>
        @media print {
            /* Everything that is furniture rather than the report. */
            .fi-sidebar,
            .fi-topbar,
            .fi-header,
            .fi-sidebar-close-overlay,
            .staff-month-report__controls {
                display: none !important;
            }

            .fi-main,
            .fi-main-ctn,
            .fi-page {
                padding: 0 !important;
                margin: 0 !important;
                max-width: none !important;
            }

            /* Filament's cards carry a shadow and a dark background that
               print as grey blocks on a white sheet. */
            .staff-month-report,
            .staff-month-report * {
                box-shadow: none !important;
                background: transparent !important;
                color: #000 !important;
            }

            .staff-month-report table {
                width: 100%;
                border-collapse: collapse;
            }

            .staff-month-report th,
            .staff-month-report td {
                border: 1px solid #999 !important;
                padding: 4px 6px !important;
                font-size: 11px !important;
            }

            /* A person's row split across two sheets is the one thing
               that makes a printed table unreadable. */
            .staff-month-report tr {
                break-inside: avoid;
            }

            @page {
                size: A4 landscape;
                margin: 10mm;
            }
        }
    </style>

    <div class="staff-month-report__controls flex flex-wrap items-end gap-3">
        <div>
            <label for="month" class="block text-sm font-medium">ماه</label>
            <select
                id="month"
                wire:model.live="month"
                class="mt-1 rounded-lg border-gray-300 text-sm dark:bg-gray-900"
            >
                @foreach ($this->monthOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <x-filament::button icon="heroicon-o-printer" onclick="window.print()">
            چاپ
        </x-filament::button>
    </div>

    <div class="staff-month-report space-y-4">
        <div class="text-center">
            <h2 class="text-lg font-bold">
                گزارش ماهانهٔ کارکنان — {{ $this->monthLabel() }}
            </h2>
            <p class="text-sm text-gray-500">
                {{ \App\Support\CurrentBakery::get()?->name }}
            </p>
        </div>

        @php($rows = $this->rows())

        @if ($rows->isEmpty())
            <p class="text-center text-sm text-gray-500">
                در این ماه حقوقی صادر نشده و تأخیری ثبت نشده است.
            </p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right">
                        <th>نام</th>
                        <th>حقوق ناخالص</th>
                        <th>پاداش</th>
                        <th>کسورات</th>
                        <th>نان</th>
                        <th>خالص پرداختی</th>
                        <th>علی‌الحساب بازنگشته</th>
                        <th>کسری تسویه‌نشده</th>
                        <th>روزهای تأخیر</th>
                        <th>هزینهٔ واقعی</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="text-right">
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['gross_formatted'] }}</td>
                            <td>{{ $row['bonus_formatted'] }}</td>
                            <td>{{ $row['deduction_formatted'] }}</td>
                            <td>{{ $row['bread_deduction_formatted'] }}</td>
                            <td>{{ $row['net_paid_formatted'] }}</td>
                            <td>{{ $row['advance_open_formatted'] }}</td>
                            <td>{{ $row['shortfall_formatted'] }}</td>
                            <td>{{ $row['late_days'] }}</td>
                            <td class="font-bold">{{ $row['total_formatted'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="text-right font-bold">
                        <td colspan="9">جمع</td>
                        <td>{{ $this->totalFormatted() }}</td>
                    </tr>
                </tfoot>
            </table>

            {{-- The arithmetic travels with the sheet. A figure argued
                 about across a desk is one whose rule should be on the
                 same piece of paper. --}}
            <p class="text-xs text-gray-500">
                حقوق ناخالص + پاداش − کسورات − نان برده‌شده + علی‌الحساب بازنگشته
                + کسری تسویه‌نشده = هزینهٔ واقعی.
            </p>
        @endif
    </div>
</x-filament-panels::page>
