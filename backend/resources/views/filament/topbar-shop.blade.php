{{--
    Which shop the panel is showing, and a way to change it.

    Absent for everyone who holds one shop, which is every member of staff
    and most owners — a switcher with one entry is furniture that teaches
    people the screen might be about some other shop.

    Rendered as a plain form rather than a Livewire component: it has to
    reach every page of the panel including ones that are not Livewire at
    all, and there is nothing here to keep in step.
--}}
@php
    $shops = auth()->user()?->reachableBakeries() ?? collect();
    $current = \App\Support\CurrentBakery::get();
@endphp

@if ($shops->count() > 1)
    <form
        method="POST"
        action="{{ route('panel.shop.switch') }}"
        class="fi-topbar-shop hidden items-center gap-2 px-3 text-sm md:flex"
    >
        @csrf
        <x-filament::icon
            icon="heroicon-o-building-storefront"
            class="h-5 w-5 text-gray-500 dark:text-gray-400"
        />
        <select
            name="bakery_id"
            onchange="this.form.submit()"
            class="fi-select-input rounded-lg border-none bg-transparent py-1 text-sm font-semibold text-gray-700 focus:ring-2 focus:ring-primary-600 dark:text-gray-200"
            aria-label="نانوایی"
        >
            @foreach ($shops as $shop)
                <option value="{{ $shop->id }}" @selected($current?->id === $shop->id)>
                    {{ $shop->name }}
                </option>
            @endforeach
        </select>
        {{-- Works without JavaScript too; hidden where the change handler runs. --}}
        <noscript>
            <button type="submit" class="fi-btn text-xs underline">نمایش</button>
        </noscript>
    </form>
@endif
