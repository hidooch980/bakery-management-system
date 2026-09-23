{{--
    گوشیِ هر کارمند، با اندرویدش.

    جدول است چون اینجا برخلاف درخواست‌ها، هر ردیف چند کلمه بیشتر
    نیست و کنارِ هم دیدنشان همان کاری است که آدم می‌خواهد بکند:
    مقایسه.
--}}
<x-filament-panels::page>
    @php($rows = $this->rows())
    @php($stranded = $rows->filter(fn ($row) => $row['stranded']))

    @if ($stranded->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                {{ $stranded->count() }} گوشی نسخهٔ تازه را نمی‌گیرد
            </x-slot>

            <p class="text-sm">
                اپِ فعلی روی این گوشی‌ها کار می‌کند، ولی هیچ نسخهٔ
                تازه‌ای رویشان نصب نمی‌شود — اندرویدشان از
                <b>{{ $this->minimumAndroid() }}</b> (اندروید ۷.۰)
                پایین‌تر است. اندروید این را موقع <b>نصب</b> بررسی
                می‌کند نه بعدش، و همین است که مسئله را نامرئی می‌کند.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">هنوز کارمندی نیست.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right">
                        <th class="pb-2">نام</th>
                        <th class="pb-2">گوشی</th>
                        <th class="pb-2">اندروید</th>
                        <th class="pb-2">نسخهٔ اپ</th>
                        <th class="pb-2">آخرین استفاده</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-200 text-right dark:border-gray-700">
                            <td class="py-2">{{ $row['user'] }}</td>

                            @if ($row['never_signed_in'])
                                <td class="py-2 text-gray-500" colspan="4">
                                    هیچ‌وقت وارد نشده
                                </td>
                            @else
                                <td class="py-2">{{ $row['device'] ?? '—' }}</td>

                                <td class="py-2">
                                    {{ $row['os'] ?? 'نامشخص' }}
                                    @if ($row['stranded'])
                                        <x-filament::badge color="danger" class="inline-flex">
                                            به‌روزرسانی نمی‌گیرد
                                        </x-filament::badge>
                                    @endif
                                </td>

                                <td class="py-2">{{ $row['app_version'] ?? 'نامشخص' }}</td>
                                <td class="py-2 text-gray-500">{{ $row['last_used'] ?? '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- گفته می‌شود، چون «نامشخص» بدون توضیح شبیه ایراد
                 می‌خوانَد در حالی که خودش یک خبر است. --}}
            <p class="mt-4 text-xs text-gray-500">
                «نامشخص» یعنی آن گوشی از وقتی این قابلیت آمده با سرور
                حرف نزده — یعنی یا اپ به‌روز نشده، یا نمی‌تواند بشود.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
