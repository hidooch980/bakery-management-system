<?php

namespace App\Filament\Resources\StaffAdvanceRequestResource\Pages;

use App\Filament\Resources\StaffAdvanceRequestResource;
use App\Models\StaffAdvanceRequest;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListStaffAdvanceRequests extends ListRecords
{
    protected static string $resource = StaffAdvanceRequestResource::class;

    /** Opens on the ones waiting, because they are the only ones that are work. */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('در انتظار پاسخ')
                ->modifyQueryUsing(fn ($query) => $query->pending())
                ->badge(StaffAdvanceRequest::query()->pending()->count())
                ->badgeColor('warning'),

            'all' => Tab::make('همه'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
