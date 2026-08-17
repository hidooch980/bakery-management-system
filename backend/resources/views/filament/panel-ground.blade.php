{{--
    The app's ground, on the panel.

    Filament builds its greys from the palette hex, and the darkest steps
    of that ramp are what the whole panel sits on — but a generated ramp
    lands on a blue-grey. The app's dark is a near-black, and staff move
    between the two all day, so the grounds have to be the same colour
    rather than nearly.

    The four values below are AppColors.iron, ironSurface, ironCard and
    ironLine, in the order the panel stacks them.

    Written as a style block rather than a compiled theme on purpose: the
    server has no asset pipeline for the panel, so a Tailwind theme would
    have to be built somewhere before it could be deployed, and this needs
    no build at all.
--}}
<style>
    .dark {
        --gray-950: 17 18 20;   /* #111214 — the ground */
        --gray-900: 23 25 29;   /* #17191D — surfaces */
        --gray-800: 29 31 35;   /* #1D1F23 — cards */
        --gray-700: 42 44 48;   /* #2A2C30 — lines */
    }

    /*
        Black on the yellow, never white.

        Filament picks a foreground for a filled button from its own
        contrast rule, and on this yellow it lands on white — 1.63:1, a
        label nobody can read. The app had the same bug in seven places
        after the repaint; this is the panel's copy of it.
    */
    .fi-btn[class*="fi-color-primary"]:not(.fi-btn-outlined):not([class*="fi-link"]),
    .fi-badge[class*="fi-color-primary"] {
        color: rgb(23 21 10);   /* #17150A */
    }

    /* Tabular figures wherever numbers stack into a column. A money column
       that does not line up is read one row at a time. */
    .fi-ta-text-item-label,
    .fi-in-text,
    .fi-ta-col-wrp {
        font-variant-numeric: tabular-nums;
    }
</style>
