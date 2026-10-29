<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\Jalali;
use App\Support\SellerDailyReport;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class SellerDailyDebtReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'ریز بدهی و سهم روزانه فروشنده';

    protected static ?string $title = 'ریز بدهی فروشنده، سهم روزانه آرد و کارتخوان';

    protected static string $view = 'filament.pages.seller-daily-debt-report';

    public ?string $sellerId = null;

    public ?string $from = null;

    public ?string $to = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        [$start] = Jalali::currentQuotaPeriod();
        $this->from = $start->toDateString();
        $this->to = today()->toDateString();
    }

    public function sellers()
    {
        return User::ofCurrentBakery()->role('seller')->orderBy('name')->get(['id', 'name']);
    }

    public function report(): ?array
    {
        if ($this->sellerId === null || $this->sellerId === '') {
            return null;
        }

        $seller = User::ofCurrentBakery()->role('seller')->findOrFail($this->sellerId);

        $this->resetErrorBag();
        try {
            return SellerDailyReport::build($seller, $this->from, $this->to);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return null;
        }
    }
}
