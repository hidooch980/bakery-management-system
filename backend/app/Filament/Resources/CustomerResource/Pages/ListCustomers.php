<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Widgets\CustomerDebtsTable;
use App\Filament\Widgets\DueFollowUpsTable;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Both of these were written, tested, and mounted on no page at all:
     * the dashboard lists its widgets by hand and neither was in it, so
     * the collection list and the call list rendered nowhere. Every other
     * widget in the panel hangs off the list page of the resource it is
     * about, and these belong here.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            DueFollowUpsTable::class,
            CustomerDebtsTable::class,
        ];
    }
}
