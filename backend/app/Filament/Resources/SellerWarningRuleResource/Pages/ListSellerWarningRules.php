<?php

namespace App\Filament\Resources\SellerWarningRuleResource\Pages;

use App\Filament\Resources\SellerWarningRuleResource;
use App\Models\SellerWarningRule;
use Filament\Resources\Pages\ListRecords;

class ListSellerWarningRules extends ListRecords
{
    protected static string $resource = SellerWarningRuleResource::class;

    public function mount(): void
    {
        SellerWarningRule::ensureDefaults();
        parent::mount();
    }

    public function getSubheading(): ?string
    {
        return 'هر صبح ساعت ۱۰:۱۰ قوانین فعال روی کارکرد دیروز سنجیده می‌شوند و هر مورد به‌صورت «اخطار در انتظار تأیید» در درخواست‌های تنبیه می‌آید. چیزی از حقوق کسر نمی‌شود. قوانین نانینو از فهرست تخلفات برنامهٔ خریدار نانینو گرفته شده‌اند.';
    }
}
