<?php

namespace App\Filament\Resources\StaffPenaltyRequestResource\Pages;

use App\Filament\Resources\StaffPenaltyRequestResource;
use App\Models\StaffPenaltyRequest;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListStaffPenaltyRequests extends ListRecords
{
    protected static string $resource = StaffPenaltyRequestResource::class;

    /** Opens on the ones waiting, because they are the only ones that are work. */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('در انتظار تأیید')
                ->modifyQueryUsing(fn ($query) => $query->pending())
                ->badge(StaffPenaltyRequest::query()->pending()->count())
                ->badgeColor('warning'),

            'all' => Tab::make('همه'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
