<x-filament-panels::page>
    @php($rows = $this->rows())
    @php($open = collect($rows)->whereIn('status', ['pending', 'ambiguous']))
    @php($accounts = $this->accounts())

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
            <div class="mt-1 text-2xl font-extrabold text-emerald-600">{{ number_format($open->where('status', 'pending')->where('direction', 'in')->sum('amount') * 10) }} ریال</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">برداشت‌های منتظر</div>
            <div class="mt-1 text-2xl font-extrabold text-rose-600">{{ number_format($open->where('status', 'pending')->where('direction', 'out')->sum('amount') * 10) }} ریال</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="صندوق تأیید" description="هیچ پیامکی خودکار ثبت نمی‌شود. حقوق، خرید آرد، قسط، علی‌الحساب و هزینه‌ای که از قبل ثبت شده، خودش «قبلاً ثبت شده» می‌خورد و دوباره ثبت نمی‌شود. فقط پیامک‌هایی که از گوشی مدیر بعد از روشن کردن این بخش رسیده‌اند اینجا هستند.">
        @if (empty($rows))
            <div class="py-6 text-center text-sm text-gray-500">هنوز پیامکی نرسیده. در برنامهٔ گوشی، از «پیامک‌های بانکی» خواندن پیامک را روشن کنید.</div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-500">
                    <tr class="border-b dark:border-white/10">
                        <th class="p-2 text-start">زمان</th>
                        <th class="p-2 text-start">نوع</th>
                        <th class="p-2 text-start">مبلغ</th>
                        <th class="p-2 text-start">بانک / حساب</th>
                        <th class="p-2 text-start">حساب نانوایی</th>
                        <th class="p-2 text-start">دسته</th>
                        <th class="p-2 text-start">وضعیت / کار</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php($isOpen = in_array($r['status'], ['pending', 'ambiguous']))
                        <tr class="border-b align-top dark:border-white/5" wire:key="sms-{{ $r['id'] }}">
                            <td class="p-2 whitespace-nowrap">{{ $r['received_at_jalali'] }}</td>
                            <td class="p-2">
                                <x-filament::badge :color="$r['direction'] === 'in' ? 'success' : 'danger'">{{ $r['direction'] === 'in' ? 'واریز' : 'برداشت' }}</x-filament::badge>
                            </td>
                            <td class="p-2 whitespace-nowrap font-bold {{ $r['direction'] === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format($r['amount'] * 10) }} ریال
                                <details class="mt-1 text-xs font-normal text-gray-500"><summary class="cursor-pointer">متن پیامک</summary><div class="whitespace-pre-line">{{ $r['body'] }}</div></details>
                            </td>
                            <td class="p-2 whitespace-nowrap">{{ $r['bank'] ?? '—' }} • …{{ $r['last4'] ?? '؟' }}</td>
                            <td class="p-2">
                                @if ($isOpen && ! $r['bank_account_id'])
                                    <select wire:model="account.{{ $r['id'] }}" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        <option value="">حساب را انتخاب کنید</option>
                                        @foreach ($accounts as $aid => $title)
                                            <option value="{{ $aid }}">{{ $title }}</option>
                                        @endforeach
                                    </select>
                                    <div class="mt-1 text-xs text-danger-600">حساب وصل نشده</div>
                                @else
                                    {{ $r['account_name'] ?? '—' }}
                                @endif
                            </td>
                            <td class="p-2">
                                @if ($r['status'] === 'pending')
                                    <select wire:model="category.{{ $r['id'] }}" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        @foreach ($this->categoriesFor($r['direction']) as $key => $label)
                                            <option value="{{ $key }}" @selected($key === ($r['suggested_category'] ?? 'other'))>{{ $r['direction'] === 'in' ? 'درآمد' : 'هزینه' }} — {{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    {{ $r['direction'] === 'in' ? 'درآمد' : 'هزینه' }} — {{ $r['suggested_category_label'] ?? 'سایر' }}
                                @endif
                            </td>
                            <td class="p-2">
                                @if ($isOpen && $r['possible_duplicate_of'])
                                    <div class="mb-1 text-xs font-bold text-warning-600">⚠ شاید تکراری: همین مبلغ ساعت {{ $r['possible_duplicate_of']['at'] }} هم آمده</div>
                                @endif
                                @if ($r['status'] === 'matched')
                                    <x-filament::badge color="success">قبلاً ثبت شده</x-filament::badge>
                                    <div class="mt-1 text-xs text-gray-500">{{ $r['matched_label'] }}</div>
                                @elseif ($r['status'] === 'ambiguous')
                                    <div class="mb-1 text-xs font-bold text-warning-600">چند ثبت با همین مبلغ؛ کدام است؟</div>
                                    <div class="flex flex-col gap-1">
                                        @foreach ($r['candidates'] as $c)
                                            <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-link" wire:click="link({{ $r['id'] }}, {{ $c['id'] }})">همین است: {{ $c['label'] }}</x-filament::button>
                                        @endforeach
                                        <x-filament::button size="xs" color="gray" outlined wire:click="ignore({{ $r['id'] }})">نادیده</x-filament::button>
                                    </div>
                                @elseif ($r['status'] === 'pending')
                                    <div class="flex gap-2 whitespace-nowrap">
                                        <x-filament::button size="sm" wire:click="confirm({{ $r['id'] }})">{{ $r['direction'] === 'in' ? 'ثبت درآمد' : 'ثبت هزینه' }}</x-filament::button>
                                        <x-filament::button size="sm" color="gray" outlined wire:click="ignore({{ $r['id'] }})">نادیده</x-filament::button>
                                    </div>
                                @elseif ($r['status'] === 'confirmed')
                                    <x-filament::badge color="success">ثبت شد</x-filament::badge>
                                    <div class="mt-1 text-xs text-gray-500">{{ $r['matched_label'] }}</div>
                                @else
                                    <x-filament::badge color="gray">نادیده</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="حساب‌ها و کارت‌ها" description="بانک سپه در پیامک شمارهٔ حساب را می‌نویسد؛ چهار رقم آخر حساب (یا کارت) را به حساب نانوایی وصل کنید.">
        <div class="divide-y dark:divide-white/10">
            @foreach ($this->mappings() as $m)
                <div class="flex items-center justify-between py-2 text-sm" wire:key="map-{{ $m['id'] }}">
                    <div>…{{ $m['last4'] }}</div>
                    <div class="flex items-center gap-3">
                        <span>{{ $m['account'] }}</span>
                        <x-filament::button size="xs" color="gray" outlined wire:click="deleteMapping({{ $m['id'] }})">برداشتن</x-filament::button>
                    </div>
                </div>
            @endforeach
            @foreach ($this->unmapped() as $digits)
                <div class="flex items-center justify-between py-2 text-sm">
                    <div>…{{ $digits }} <span class="text-danger-600">— در پیامک‌ها آمده ولی به حسابی وصل نیست</span></div>
                    <x-filament::button size="xs" wire:click="useDigits('{{ $digits }}')">وصل کن</x-filament::button>
                </div>
            @endforeach
        </div>
        <form wire:submit="saveMapping" class="mt-4 flex flex-wrap items-end gap-3">
            <label class="text-sm">چهار رقم آخر
                <input type="text" inputmode="numeric" maxlength="4" wire:model="newLast4" class="mt-1 block w-28 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" />
            </label>
            <label class="text-sm">حساب نانوایی
                <select wire:model="newAccount" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">انتخاب کنید</option>
                    @foreach ($accounts as $aid => $title)
                        <option value="{{ $aid }}">{{ $title }}</option>
                    @endforeach
                </select>
            </label>
            <x-filament::button type="submit">ذخیره</x-filament::button>
        </form>
        @error('newLast4') <div class="mt-1 text-xs text-danger-600">{{ $message }}</div> @enderror
        @error('newAccount') <div class="mt-1 text-xs text-danger-600">{{ $message }}</div> @enderror
    </x-filament::section>
</x-filament-panels::page>
