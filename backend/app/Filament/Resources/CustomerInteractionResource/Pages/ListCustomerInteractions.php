<?php

namespace App\Filament\Resources\CustomerInteractionResource\Pages;

use App\Filament\Resources\CustomerInteractionResource;
use App\Models\CustomerInteraction;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListCustomerInteractions extends ListRecords
{
    protected static string $resource = CustomerInteractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('ثبت تماس'),
        ];
    }

    /**
     * The call list first, because it is the only tab with work in it.
     * Everything else here is history.
     */
    public function getTabs(): array
    {
        return [
            'due' => Tab::make('امروز')
                ->modifyQueryUsing(fn ($query) => $query->due())
                ->badge(CustomerInteraction::query()->due()->count())
                ->badgeColor('warning'),

            'open' => Tab::make('پیگیری‌های باز')
                ->modifyQueryUsing(fn ($query) => $query->open()),

            'all' => Tab::make('همه'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'due';
    }
}
