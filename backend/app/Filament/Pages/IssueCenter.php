<?php

namespace App\Filament\Pages;

use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Everything the system found wrong with its own records, in one place.
 *
 * Issues are recomputed on every visit rather than stored, so a problem
 * disappears from here the moment the underlying data is put right.
 */
class IssueCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'مرکز خطاها';

    protected static ?string $title = 'مرکز خطاها';

    protected static ?int $navigationSort = -5;

    protected static string $view = 'filament.pages.issue-center';

    /** @return Collection<int, SystemIssue> */
    public function getIssues(): Collection
    {
        return app(IssueScanner::class)->scan();
    }

    public function getFixableCount(): int
    {
        return $this->getIssues()->filter->isAutoFixable()->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rescan')
                ->label('بررسی دوباره')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => Notification::make()
                    ->title('بررسی انجام شد')
                    ->success()
                    ->send()),

            Action::make('autoFix')
                ->label('اصلاح خودکار موارد ایمن')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->visible(fn () => $this->getFixableCount() > 0)
                ->requiresConfirmation()
                ->modalHeading('اصلاح خودکار')
                ->modalDescription(
                    'فقط خطاهایی اصلاح می‌شوند که اصلاحشان یک تراکنش جدید و'
                    .' توضیح‌دار ثبت می‌کند؛ هیچ سابقه‌ای تغییر نمی‌کند یا پاک نمی‌شود.'
                    .' این کار علت اصلی خطا را برطرف نمی‌کند و باید جداگانه بررسی شود.'
                )
                ->modalSubmitActionLabel('اصلاح کن')
                ->action(fn () => $this->applyAutoFixes()),
        ];
    }

    private function applyAutoFixes(): void
    {
        $applied = [];

        foreach ($this->getIssues()->filter->isAutoFixable() as $issue) {
            $applied[] = ($issue->autoFix)();
        }

        if ($applied === []) {
            Notification::make()
                ->title('مورد قابل اصلاحی نبود')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(count($applied).' مورد اصلاح شد')
            ->body(implode(' — ', $applied))
            ->success()
            ->persistent()
            ->send();
    }
}
