{{-- RTL layout for the Filament panel. The Vazirmatn font itself is loaded
     by the panel's ->font('Vazirmatn') call. --}}
<style>
    :root {
        --font-family: 'Vazirmatn', ui-sans-serif, system-ui, sans-serif;
    }

    .fi-body,
    .fi-body input,
    .fi-body button,
    .fi-body select,
    .fi-body textarea {
        font-family: 'Vazirmatn', ui-sans-serif, system-ui, sans-serif;
    }

    /* Figures stay readable inside the RTL layout. */
    .fi-ta-table td,
    .fi-wi-stats-overview-stat-value {
        font-variant-numeric: tabular-nums;
    }

    /* Larger, easier tap targets — the panel is also used on tablets in the shop. */
    .fi-btn {
        min-height: 2.5rem;
    }

    .fi-ta-actions .fi-btn {
        min-height: 2.25rem;
    }
</style>

<script>
    // Filament renders LTR by default; flip the document once it is ready.
    document.documentElement.setAttribute('dir', 'rtl');
    document.documentElement.setAttribute('lang', 'fa');
</script>
