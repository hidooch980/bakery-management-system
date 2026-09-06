{{--
    One answer, then what needs the owner, then the figures.

    Styled with a scoped block rather than utility classes: the server has
    no asset pipeline for the panel, so anything needing `npm run build`
    cannot be deployed. Everything here is plain CSS on custom properties
    Filament already defines, which means it follows the panel's own light
    and dark grounds without naming a single colour twice.
--}}
<x-filament-panels::page>
    @php
        $health = $this->health();
        $answer = $this->answer();
        $issues = $this->issues();
        $critical = $issues->where('severity', \App\Support\SystemIssue::CRITICAL);
        $rest = $issues->where('severity', '!=', \App\Support\SystemIssue::CRITICAL);
    @endphp

    <style>
        .today-answer {
            padding: 0 0 1.75rem;
            border-bottom: 1px solid rgb(var(--gray-200));
        }

        .dark .today-answer { border-bottom-color: rgb(var(--gray-800)); }

        .today-answer p {
            margin: 0;
            font-size: clamp(1.75rem, 4.5vw, 2.6rem);
            line-height: 1.3;
            font-weight: 700;
            text-wrap: balance;
        }

        .today-answer .yours { font-weight: 500; color: rgb(var(--gray-500)); }
        .today-answer.tone-clear .system { color: rgb(var(--success-600)); }
        .today-answer.tone-sound .system { color: rgb(var(--success-600)); }
        .today-answer.tone-fail .system { color: rgb(var(--danger-600)); }
        .dark .today-answer.tone-clear .system,
        .dark .today-answer.tone-sound .system { color: rgb(var(--success-400)); }
        .dark .today-answer.tone-fail .system { color: rgb(var(--danger-400)); }

        .today-stamp {
            margin-top: 0.9rem;
            font-size: 0.8rem;
            color: rgb(var(--gray-500));
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .today-needs { margin-top: 1.5rem; display: flex; flex-direction: column; }

        .today-need {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            padding: 0.9rem 0;
            border-bottom: 1px solid rgb(var(--gray-100));
            text-decoration: none;
            color: inherit;
        }

        .dark .today-need { border-bottom-color: rgb(var(--gray-800)); }

        .today-need:hover { background: rgb(var(--gray-50)); }
        .dark .today-need:hover { background: rgb(var(--gray-900)); }

        .today-stripe {
            width: 3px;
            border-radius: 2px;
            align-self: stretch;
            flex: none;
            background: rgb(var(--gray-300));
        }

        .dark .today-stripe { background: rgb(var(--gray-700)); }
        .today-need.is-critical .today-stripe { background: rgb(var(--danger-500)); }
        .today-need.is-warning .today-stripe { background: rgb(var(--warning-500)); }

        .today-need-body { min-width: 0; }
        .today-need-title { font-weight: 600; font-size: 0.95rem; }
        .today-need-detail { font-size: 0.82rem; color: rgb(var(--gray-500)); margin-top: 0.15rem; }

        .today-outlook {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid rgb(var(--gray-200));
            display: flex;
            flex-direction: column;
            gap: .6rem;
        }
        .dark .today-outlook { border-top-color: rgb(var(--gray-800)); }
        .today-outlook-line small {
            display: block;
            color: rgb(var(--gray-500));
            margin-top: .1rem;
        }
        .today-outlook-line.attention > div { color: rgb(var(--warning-600)); font-weight: 600; }
        .dark .today-outlook-line.attention > div { color: rgb(var(--warning-400)); }

        .today-figures {
            margin-top: 1.75rem;
            padding-top: 1rem;
            border-top: 1px solid rgb(var(--gray-200));
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem 1.6rem;
            font-size: 0.85rem;
            color: rgb(var(--gray-500));
        }

        .dark .today-figures { border-top-color: rgb(var(--gray-800)); }

        .today-figures b {
            font-weight: 600;
            color: rgb(var(--gray-800));
            font-variant-numeric: tabular-nums;
        }

        .dark .today-figures b { color: rgb(var(--gray-200)); }

        .today-broken {
            margin-top: 1.25rem;
            border: 1px solid rgb(var(--danger-500));
            border-radius: 0.5rem;
            padding: 1rem 1.1rem;
        }

        .today-broken h3 { margin: 0 0 0.5rem; font-size: 0.95rem; font-weight: 700; }
        .today-broken ul { margin: 0; padding-inline-start: 1.1rem; font-size: 0.85rem; }
        .today-broken li { margin-bottom: 0.3rem; }

        .today-nothing {
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: rgb(var(--gray-500));
        }
    </style>

    <x-filament::section>
        <div class="today-answer tone-{{ $answer['tone'] }}">
            <p><span class="system">{{ $answer['system'] }}</span></p>
            <p class="yours">{{ $answer['yours'] }}</p>

            <div class="today-stamp">
                <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4" />
                <span>هر {{ $this->cycleCountLabel() }} چرخه همین حالا بررسی شد</span>
            </div>
        </div>

        {{-- A failure means the records contradict each other, so it is
             shown before anything else and says plainly that the figures
             below cannot be trusted yet. --}}
        @if (! $health->isSound())
            <div class="today-broken">
                <h3>سیستم با خودش نمی‌خواند</h3>
                <ul>
                    @foreach ($health->failures() as $failure)
                        <li>{{ $failure }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($issues->isEmpty())
            <p class="today-nothing">هیچ چیزی منتظر شما نیست.</p>
        @else
            <div class="today-needs">
                @foreach ($critical->concat($rest) as $issue)
                    <a
                        class="today-need {{ $issue->severity === \App\Support\SystemIssue::CRITICAL ? 'is-critical' : ($issue->severity === \App\Support\SystemIssue::WARNING ? 'is-warning' : '') }}"
                        href="{{ $issue->url ?? \App\Filament\Pages\IssueCenter::getUrl() }}"
                    >
                        <span class="today-stripe"></span>
                        <span class="today-need-body">
                            <span class="today-need-title">{{ $issue->title }}</span>
                            <span class="today-need-detail">{{ $issue->detail }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Warnings from the cycles are the shop's to look at but are not
             faults, so they sit under the issues rather than beside the
             sentence. --}}
        @if ($health->warnings() !== [])
            <div class="today-needs">
                @foreach ($health->warnings() as $warning)
                    <div class="today-need is-warning">
                        <span class="today-stripe"></span>
                        <span class="today-need-body">
                            <span class="today-need-title">{{ $warning }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Forward-looking, and only where there is enough history to
             look from. Each line carries its own basis so the owner can
             disagree with the arithmetic, not just the number. --}}
        @if ($this->outlook())
            <div class="today-outlook">
                @foreach ($this->outlook() as $line)
                    <div class="today-outlook-line {{ $line['tone'] === 'attention' ? 'attention' : '' }}">
                        <div>{{ $line['title'] }}</div>
                        <small>{{ $line['basis'] }}</small>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="today-figures">
            @foreach ($this->figures() as $figure)
                <span>{{ $figure['label'] }} <b>{{ $figure['value'] }}</b></span>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
