{{--
    The kiln ground.

    Filament builds its greys from the palette hex, but the darkest steps
    of that ramp are what the whole panel sits on, and a generated ramp
    lands on a blue-grey. The app's dark is iron — a cold oven — and staff
    move between the two all day, so the two grounds have to be the same
    colour rather than nearly.

    Written as a style block rather than a compiled theme on purpose: the
    server has no asset pipeline for the panel, so a Tailwind theme would
    have to be built somewhere before it could be deployed, and this needs
    no build at all.
--}}
<style>
    .dark {
        --gray-950: 18 22 28;   /* #12161C — the ground */
        --gray-900: 26 32 41;   /* #1A2029 — surfaces */
        --gray-800: 33 41 54;   /* #212936 — cards */
        --gray-700: 42 51 63;   /* #2A333F — lines */
    }

    /* Tabular figures wherever numbers stack into a column. A money column
       that does not line up is read one row at a time. */
    .fi-ta-text-item-label,
    .fi-in-text,
    .fi-ta-col-wrp {
        font-variant-numeric: tabular-nums;
    }
</style>
