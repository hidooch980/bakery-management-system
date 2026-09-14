<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Filament\Widgets\ExpenseByCategoryChart;
use App\Filament\Widgets\FinancialOverview;
use App\Filament\Widgets\IncomeExpenseChart;
use App\Filament\Widgets\ProfitSplitTable;
use App\Models\Expense;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    /**
     * «سایر» given a tab of its own, with its count on the badge.
     *
     * It was reachable before — the category filter has always been
     * there — but reachable is not the same as seen, and half the shop's
     * costs had collected under a label that answers nothing: over a
     * billion rial in three months, with no way to tell rent from a
     * repair. Work nobody can see is work nobody does.
     *
     * The badge is the point. A number that shrinks as rows are filed
     * says the job is finite and how much of it is left; a filter in a
     * dropdown says neither.
     */
    public function getTabs(): array
    {
        $uncategorised = Expense::query()->where('category', 'other')->count();

        return [
            'all' => Tab::make('همه'),
            'other' => Tab::make('بدون دسته')
                ->modifyQueryUsing(fn ($query) => $query->where('category', 'other'))
                ->badge($uncategorised ?: null)
                ->badgeColor('warning'),
        ];
    }

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
            FinancialOverview::class,
            IncomeExpenseChart::class,
            ExpenseByCategoryChart::class,
            ProfitSplitTable::class,
        ];
    }
}
