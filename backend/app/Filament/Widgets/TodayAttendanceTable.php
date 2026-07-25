<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\UserResource;
use App\Models\Attendance;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * At-a-glance view of who checked in today and at what time.
 */
class TodayAttendanceTable extends BaseWidget
{
    protected static ?string $heading = 'تیک حضور امروز';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Attendance::query()
                    ->with('user.roles')
                    ->whereDate('date', now()->toDateString())
                    ->orderBy('checked_in_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('کارمند')
                    ->weight('bold')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('user.roles.name')
                    ->label('نقش')
                    ->badge()
                    ->formatStateUsing(fn ($state) => UserResource::roleLabel($state))
                    ->color('info'),

                Tables\Columns\TextColumn::make('checked_in_at')
                    ->label('ساعت حضور')
                    ->dateTime('H:i')
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-clock'),
            ])
            ->emptyStateHeading('هنوز کسی تیک حضور نزده است')
            ->emptyStateIcon('heroicon-o-clock')
            ->paginated([5, 10, 25]);
    }
}
