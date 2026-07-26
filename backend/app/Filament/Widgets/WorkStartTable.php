<?php

namespace App\Filament\Widgets;

use App\Models\WorkStart;
use Filament\Widgets\Widget;

/**
 * Today's shaping and baking start times, with the late warning.
 *
 * A plain widget rather than a table: there are only ever two rows, and
 * what matters is the not-yet-started case, which a table over the records
 * could not show at all.
 */
class WorkStartTable extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.work-start-table';

    public function getBoard(): array
    {
        return WorkStart::todayBoard();
    }
}
