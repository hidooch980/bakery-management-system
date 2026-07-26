<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    // The financial picture lives here rather than crowding the main
    // dashboard — it only matters when someone is actually looking at money.
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\FinancialOverview::class,
            \App\Filament\Widgets\IncomeExpenseChart::class,
            \App\Filament\Widgets\ExpenseByCategoryChart::class,
            \App\Filament\Widgets\ProfitSplitTable::class,
        ];
    }
}
