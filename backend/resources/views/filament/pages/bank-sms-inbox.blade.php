<x-filament-panels::page>
    @php($rows = $this->rows())
    @php($open = collect($rows)->whereIn('status', ['pending', 'ambiguous']))

    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">منتظر ثبت</div>
            <div class="mt-1 text-2xl font-extrabold text-primary-600">{{ $open->count() }} پیامک</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">قبلاً ثبت شده (خودکار وصل شد)</div>
            <div class="mt-1 text-2xl font-extrabold text-emerald-600">{{ collect($rows)->where('status', 'matched')->count() }} پیامک</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">واریزهای منتظر</div>
            <div class="mt-1 text-2xl font-extrabold text-emerald-600">24,690,000 ریال</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">برداشت‌های منتظر</div>
            <div class="mt-1 text-2xl font-extrabold text-rose-600">6,333,000 ریال</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="صندوق تأیید" description="هیچ پیامکی خودکار ثبت نمی‌شود. حقوق، خرید، قسط و علی‌الحسابی که از قبل در برنامه ثبت شده، خودش «قبلاً ثبت شده» می‌خورد و دوباره ثبت نمی‌شود.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-500">
                    <tr class="border-b dark:border-white/10">
                        <th class="p-2 text-start">زمان</th>
                        <th class="p-2 text-start">نوع</th>
                        <th class="p-2 text-start">مبلغ</th>
                        <th class="p-2 text-start">بانک / کارت</th>
                        <th class="p-2 text-start">حساب نانوایی</th>
                        <th class="p-2 text-start">دستهٔ پیشنهادی</th>
                        <th class="p-2 text-start">متن پیامک</th>
                        <th class="p-2 text-start">وضعیت / کار</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr class="border-b align-top dark:border-white/5">
                            <td class="p-2 whitespace-nowrap">{{ $r['at'] }}</td>
                            <td class="p-2">
                                <x-filament::badge :color="$r['dir'] === 'in' ? 'success' : 'danger'">{{ $r['dir'] === 'in' ? 'واریز' : 'برداشت' }}</x-filament::badge>
                            </td>
                            <td class="p-2 whitespace-nowrap font-bold {{ $r['dir'] === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['amount'] }} ریال</td>
                            <td class="p-2 whitespace-nowrap">{{ $r['bank'] }} • …{{ $r['card'] }}</td>
                            <td class="p-2">
                                @if ($r['account']) {{ $r['account'] }} @else <span class="text-danger-600">کارت وصل نشده</span> @endif
                            </td>
                            <td class="p-2">{{ $r['dir'] === 'in' ? 'درآمد' : 'هزینه' }} — {{ $r['cat'] }}</td>
                            <td class="p-2 max-w-xs text-xs text-gray-500">{{ $r['body'] }}</td>
                            <td class="p-2">
                                @if (! empty($r['duplicate']))
                                    <div class="mb-1 text-xs font-bold text-warning-600">⚠ شاید تکراری: {{ $r['duplicate'] }}</div>
                                @endif
                                @if ($r['status'] === 'matched')
                                    <x-filament::badge color="success">قبلاً ثبت شده</x-filament::badge>
                                    <div class="mt-1 text-xs text-gray-500">{{ $r['matched'] }}</div>
                                @elseif ($r['status'] === 'ambiguous')
                                    <div class="mb-1 text-xs font-bold text-warning-600">چند ثبت با همین مبلغ؛ کدام است؟</div>
                                    <div class="flex flex-col gap-1">
                                        @foreach ($r['candidates'] as $c)
                                            <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-link">{{ $c }}</x-filament::button>
                                        @endforeach
                                        <x-filament::button size="xs" color="gray">هیچ‌کدام — ثبت جدید</x-filament::button>
                                    </div>
                                @elseif ($r['status'] === 'pending')
                                    <div class="flex gap-2 whitespace-nowrap">
                                        <x-filament::button size="sm">{{ $r['dir'] === 'in' ? 'ثبت درآمد' : 'ثبت هزینه' }}</x-filament::button>
                                        <x-filament::button size="sm" color="gray" outlined>نادیده</x-filament::button>
                                    </div>
                                @elseif ($r['status'] === 'confirmed')
                                    <x-filament::badge color="success">ثبت شد</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">نادیده</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="کارت‌ها و حساب‌های نانوایی" description="هر پیامک با چهار رقم آخر کارت/حساب به حساب بانکی نانوایی وصل می‌شود.">
        <div class="divide-y dark:divide-white/10">
            @foreach ($this->cards() as $c)
                <div class="flex items-center justify-between py-2 text-sm">
                    <div>کارت …{{ $c['card'] }} • {{ $c['bank'] }}</div>
                    <div class="flex items-center gap-3">
                        @if ($c['account'])
                            <span>{{ $c['account'] }}</span>
                            <x-filament::button size="xs" color="gray" outlined>تغییر</x-filament::button>
                        @else
                            <span class="text-danger-600">به حسابی وصل نشده</span>
                            <x-filament::button size="xs">وصل کن</x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
