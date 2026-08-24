<?php

namespace App\Filament\Resources\SalaryPaymentRequestResource\Pages;

use App\Filament\Resources\SalaryPaymentRequestResource;
use App\Models\SalaryPaymentRequest;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListSalaryPaymentRequests extends ListRecords
{
    protected static string $resource = SalaryPaymentRequestResource::class;

    /** Opens on the ones waiting, because they are the only ones that are work. */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('در انتظار پاسخ')
                ->modifyQueryUsing(fn ($query) => $query->pending())
                ->badge(SalaryPaymentRequest::query()->pending()->count())
                ->badgeColor('warning'),

            'all' => Tab::make('همه'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
