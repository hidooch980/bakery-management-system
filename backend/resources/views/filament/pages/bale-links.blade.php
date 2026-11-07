<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check">ذخیره تغییرات</x-filament::button>
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">دعوت‌نامه</x-slot>
        @if ($url = $this->getInviteUrl())
            <div class="flex flex-wrap items-center gap-6">
                <div class="rounded-lg bg-white p-2">{!! $this->getInviteQrSvg() !!}</div>
                <div class="space-y-2">
                    <p>این پیوند یا QR را به همکارها و کارکنان بدهید. ربات را باز کنند و «ارسال شماره» را بزنند.</p>
                    <a href="{{ $url }}" target="_blank" dir="ltr" class="font-mono text-primary-600">{{ $url }}</a>
                </div>
            </div>
        @else
            <p class="text-gray-500">نام کاربری ربات را در بالا وارد کنید تا پیوند و QR ساخته شود.</p>
        @endif
        <p class="mt-4 text-sm text-gray-500">
            نشانی وب‌هوک:
            @if ($hook = $this->getWebhookUrl())
                <span dir="ltr" class="font-mono">{{ $hook }}</span>
                — تا مدیر آن را در بله ثبت نکند، آپدیتی نمی‌رسد.
            @else
                هنوز ساخته نشده.
            @endif
        </p>
    </x-filament::section>

    @foreach ($this->getGroups() as $type => $group)
        @php($linked = collect($group['rows'])->where('linked', true)->count())
        <x-filament::section collapsible>
            <x-slot name="heading">{{ $group['label'] }} — {{ \App\Support\TodayAnswer::digits($linked) }} از {{ \App\Support\TodayAnswer::digits(count($group['rows'])) }} وصل</x-slot>
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="p-2 text-start">نام</th><th class="p-2 text-start">وضعیت</th><th></th></tr></thead>
                <tbody>
                @foreach ($group['rows'] as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="p-2">{{ $row['name'] }}</td>
                        <td class="p-2">
                            @if ($row['linked'])
                                <x-filament::badge color="success">وصل به بله</x-filament::badge>
                            @elseif ($row['has_phone'])
                                <x-filament::badge color="warning">وصل نیست — پیامک</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">شماره ندارد</x-filament::badge>
                            @endif
                        </td>
                        <td class="p-2 text-end">
                            @if ($row['linked'])
                                <x-filament::link tag="button" color="danger" size="sm"
                                    wire:click="unlink('{{ $type }}', {{ $row['id'] }})"
                                    wire:confirm="اتصال بلهٔ {{ $row['name'] }} برداشته شود؟">برداشتن اتصال</x-filament::link>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
