<x-filament-panels::page>
    @php
        $positions = $this->positions();
        $netOwed = $this->netOwedToShop();
        $netOwing = $this->netOwedByShop();
        $overdue = $this->overdueCount();
        $noPhone = $this->withoutPhoneCount();
    @endphp

    {{-- ------------------------------------------------ the whole of it --}}
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">طلب مغازه از همکاران</div>
            <div class="mt-1 text-2xl font-extrabold text-rose-600 dark:text-rose-400">
                {{ $this->bags($netOwed) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->kg($netOwed) }}
                @if ($value = $this->money($netOwed))
                    — {{ $value }} به قیمت ثبت‌شدهٔ آرد
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">بدهی مغازه به همکاران</div>
            <div class="mt-1 text-2xl font-extrabold text-amber-600 dark:text-amber-400">
                {{ $this->bags($netOwing) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->kg($netOwing) }} — در انبار شماست
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">همکار با حساب باز</div>
            <div class="mt-1 text-2xl font-extrabold text-gray-800 dark:text-gray-100">
                {{ \App\Support\Qty::format($positions->count()) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                @if ($overdue > 0)
                    <span class="font-bold text-rose-600 dark:text-rose-400">{{ \App\Support\Qty::format($overdue) }} مورد نیاز به پیگیری</span>
                @else
                    همه در بازهٔ عادی
                @endif
            </div>
        </x-filament::section>
    </div>

    {{--
        Said once, at the top, because it is the reason a chase stalls: the
        report can name the debt and the shop still has no way to ask for
        it back.
    --}}
    @if ($noPhone > 0)
        <x-filament::section>
            <div class="flex gap-3">
                <x-filament::icon icon="heroicon-o-phone-x-mark"
                    class="h-5 w-5 shrink-0 text-amber-500" />
                <div class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                    <span class="font-bold">{{ \App\Support\Qty::format($noPhone) }} همکار شمارهٔ تماس ندارد.</span>
                    تا شماره‌شان ثبت نشود، این صفحه می‌گوید چه کسی بدهکار است ولی راهی
                    برای پرسیدنش نمی‌گذارد. شماره را در پروندهٔ همکار یا در فرم آرد امانی ثبت کنید.
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------ the accounts --}}
    @if ($positions->isEmpty())
        <x-filament::section>
            <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                هیچ آرد امانی تسویه‌نشده‌ای وجود ندارد.
            </div>
        </x-filament::section>
    @endif

    @foreach ($positions as $partner)
        @php $isOpen = $this->openPartner === $partner->key; @endphp

        <x-filament::section>
            {{-- The account line: click it and the dealings open below. --}}
            <button type="button"
                wire:click="toggle(@js($partner->key))"
                class="-m-2 flex w-full items-center gap-3 rounded-lg p-2 text-right transition hover:bg-gray-50 dark:hover:bg-white/5">

                <x-filament::icon
                    :icon="$isOpen ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-left'"
                    class="h-4 w-4 shrink-0 text-gray-400" />

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold text-gray-900 dark:text-gray-100">{{ $partner->name }}</span>

                        @if ($partner->isOverdue())
                            <x-filament::badge color="danger" size="sm">نیاز به پیگیری</x-filament::badge>
                        @endif

                        @if ($partner->dateIsApproximate)
                            <x-filament::badge color="warning" size="sm">تاریخ تحویل نامعلوم</x-filament::badge>
                        @endif
                    </div>

                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($partner->phone)
                            <span dir="ltr">{{ $partner->phone }}</span>
                        @else
                            <span class="text-amber-600 dark:text-amber-400">بدون شماره تماس</span>
                        @endif
                        @if ($partner->bagsLent > 0)
                            — {{ $partner->ageLabel() }}
                        @endif
                    </div>
                </div>

                <div class="shrink-0 text-left">
                    @php $net = $partner->netBags(); @endphp
                    <div @class([
                        'text-lg font-extrabold',
                        'text-rose-600 dark:text-rose-400' => $partner->shopIsOwed(),
                        'text-amber-600 dark:text-amber-400' => $partner->shopOwes(),
                        'text-gray-500 dark:text-gray-400' => ! $partner->shopIsOwed() && ! $partner->shopOwes(),
                    ])>
                        {{ $this->bags(abs($net)) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($partner->shopIsOwed())
                            طلب مغازه
                        @elseif ($partner->shopOwes())
                            بدهی مغازه
                        @else
                            تسویه
                        @endif
                    </div>
                </div>
            </button>

            {{--
                The netting, spelled out on the account line itself. A
                partner shown as eight sacks when the list behind him says
                twenty and twelve is a figure the owner cannot check, and
                an unexplained figure is one he has to take on trust.
            --}}
            @if ($offset = $partner->offsetLabel())
                <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                    {{ $offset }}
                </div>
            @endif

            {{-- ------------------------------------------- the dealings --}}
            @if ($isOpen)
                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-white/10">
                    <div class="mb-2 text-xs font-bold text-gray-500 dark:text-gray-400">
                        معاملات این همکار
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="py-2 pl-3 text-right font-medium">تاریخ تحویل</th>
                                    <th class="py-2 pl-3 text-right font-medium">نوع</th>
                                    <th class="py-2 pl-3 text-right font-medium">مقدار</th>
                                    <th class="py-2 pl-3 text-right font-medium">وضعیت</th>
                                    <th class="py-2 text-right font-medium">توضیحات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->dealings($partner) as $row)
                                    <tr class="border-b border-gray-50 last:border-0 dark:border-white/5">
                                        <td class="py-2 pl-3 whitespace-nowrap align-top">
                                            {{ $this->date($row->occurred_on) }}
                                            @if ($row->date_is_approximate)
                                                <div class="text-xs text-amber-600 dark:text-amber-400">تاریخ دقیق نیست</div>
                                            @endif
                                        </td>
                                        <td class="py-2 pl-3 align-top">
                                            <x-filament::badge
                                                :color="$row->direction === 'borrowed' ? 'success' : 'warning'"
                                                size="sm">
                                                {{ $row->direction_label }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="py-2 pl-3 whitespace-nowrap align-top font-medium">
                                            {{ $row->quantity_label }}
                                        </td>
                                        <td class="py-2 pl-3 whitespace-nowrap align-top">
                                            @if ($row->is_settled)
                                                <span class="text-emerald-600 dark:text-emerald-400">
                                                    تسویه {{ $this->date($row->settled_on) }}
                                                </span>
                                            @else
                                                <span class="text-rose-600 dark:text-rose-400">تسویه‌نشده</span>
                                            @endif
                                        </td>
                                        <td class="py-2 align-top text-xs text-gray-500 dark:text-gray-400">
                                            {{ $row->note ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span>تحویلی باز: <span class="font-bold">{{ $this->bags($partner->bagsLent) }}</span></span>
                        <span>دریافتی باز: <span class="font-bold">{{ $this->bags($partner->bagsBorrowed) }}</span></span>
                        @if ($partner->shopIsOwed() && ($value = $this->money($partner->netBags())))
                            <span>ارزش طلب: <span class="font-bold">{{ $value }}</span></span>
                        @endif
                    </div>

                    <div class="mt-3">
                        <x-filament::link
                            href="{{ \App\Filament\Resources\ConsignmentFlourResource::getUrl('index') }}"
                            icon="heroicon-m-arrow-left-circle"
                            size="sm">
                            ثبت یا تسویه در صفحهٔ آرد امانی
                        </x-filament::link>
                    </div>
                </div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
