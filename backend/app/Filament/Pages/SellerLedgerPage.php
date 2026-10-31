<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\SellerLedger;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * «دفتر حساب فروشنده»: every debt the seller took on and every handover, in
 * date order, with the balance running beside them — and a warning when
 * that balance and the account figure disagree.
 */
class SellerLedgerPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'دفتر حساب فروشنده';

    protected static ?string $title = 'دفتر حساب فروشنده';

    protected static ?string $slug = 'seller-ledger';

    protected static string $view = 'filament.pages.seller-ledger';

    #[Url(as: 'seller')]
    public ?string $sellerId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        if ($this->sellerId === null && ($only = $this->sellers())->count() === 1) {
            $this->sellerId = (string) $only->first()->id;
        }
    }

    public function sellers()
    {
        return User::ofCurrentBakery()->role('seller')->orderBy('name')->get(['id', 'name']);
    }

    public function ledger(): ?array
    {
        if (blank($this->sellerId)) {
            return null;
        }

        return SellerLedger::payload(User::ofCurrentBakery()->role('seller')->findOrFail($this->sellerId));
    }
}
