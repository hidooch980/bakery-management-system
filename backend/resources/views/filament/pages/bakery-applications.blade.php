{{--
    درخواست‌ها، در انتظارها اول.

    جدول نیست چون هر ردیف چند خط حرف دارد — نام، تلفن، شهر، و
    توضیحی که خودش نوشته — و آن‌ها را در ستون فشردن یعنی صاحب باید
    روی هر کدام کلیک کند تا ببیند طرف چه گفته.
--}}
<x-filament-panels::page>
    @php($applications = $this->applications())

    @if ($applications->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500">
                هنوز کسی درخواست نداده. نشانیِ فرم:
                <code class="text-xs">{{ route('signup') }}</code>
            </p>
        </x-filament::section>
    @else
        <div class="space-y-4">
            @foreach ($applications as $application)
                <x-filament::section>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-1">
                            <h3 class="text-base font-bold">
                                {{ $application->bakery_name }}
                                @if ($application->city)
                                    <span class="text-sm font-normal text-gray-500">
                                        — {{ $application->city }}
                                    </span>
                                @endif
                            </h3>

                            <p class="text-sm">
                                {{ $application->owner_name }}
                                ·
                                <a href="tel:{{ preg_replace('/\D+/', '', $application->phone) }}"
                                   class="underline">{{ $application->phone }}</a>
                                @if ($application->email)
                                    · {{ $application->email }}
                                @endif
                            </p>

                            <p class="text-xs text-gray-500">
                                {{ $application->payload()['asked_on_label'] }}
                            </p>

                            @if ($application->note)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                    {{ $application->note }}
                                </p>
                            @endif

                            @unless ($application->is_pending)
                                <p class="mt-2 text-sm">
                                    <x-filament::badge
                                        :color="$application->status === \App\Models\BakeryApplication::APPROVED ? 'success' : 'danger'"
                                    >
                                        {{ $application->payload()['status_label'] }}
                                    </x-filament::badge>

                                    @if ($application->reviewedBy)
                                        <span class="text-xs text-gray-500">
                                            — {{ $application->reviewedBy->name }}،
                                            {{ $application->payload()['reviewed_on_label'] }}
                                        </span>
                                    @endif
                                </p>

                                @if ($application->rejection_reason)
                                    <p class="text-xs text-gray-500">
                                        دلیل: {{ $application->rejection_reason }}
                                    </p>
                                @endif
                            @endunless
                        </div>

                        @if ($application->is_pending)
                            <div class="flex shrink-0 gap-2">
                                {{ ($this->approveAction)(['id' => $application->id]) }}
                                {{ ($this->rejectAction)(['id' => $application->id]) }}
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
