<?php

namespace App\Filament\Pages;

use App\Models\FlourAllocation;
use App\Support\DieselLedger;
use App\Support\Jalali;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class DieselMonthlyReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationGroup = 'انبار و سهمیه';

    protected static ?string $navigationLabel = 'گردش ماهانه و انتقال گازوئیل';

    protected static ?string $title = 'گردش ماهانهٔ گازوئیل و انتقال مانده';

    protected static string $view = 'filament.pages.diesel-monthly-report';

    public ?string $month = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        $this->month = Jalali::format(FlourAllocation::monthStartFor(today()), 'Y/m');
    }

    public function report(): ?array
    {
        $this->resetErrorBag();
        try {
            return DieselLedger::month($this->month);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return null;
        }
    }
}
