<?php

namespace App\Filament\Pages;

use App\Models\IssueAcknowledgement;
use App\Support\CurrentBakery;
use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the system found wrong with its own records, in one place.
 *
 * Issues are recomputed on every visit rather than stored, so a problem
 * disappears from here the moment the underlying data is put right.
 *
 * Not every issue is a mistake, though. This shop pays no wages through the
 * system and keeps rent and utilities at zero on purpose; those are
 * reported every single time, one of them as «بحرانی», and always will be.
 * So an issue can be answered — «می‌دانم، تصمیمم همین است» — and moves to a
 * decided list with the reason beside it. It is still on the page. It just
 * stops counting as open, which is what keeps the badge worth looking at.
 *
 * An answer covers the problem at the size it was. If it grows past that,
 * it comes back at the top with what changed — see IssueAcknowledgement.
 */
class IssueCenter extends Page
{
    /** How long the sidebar badge is held before it is worked out again. */
    private const BADGE_MINUTES = 5;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'مرکز خطاها';

    protected static ?string $title = 'مرکز خطاها';

    protected static ?int $navigationSort = -5;

    protected static string $view = 'filament.pages.issue-center';

    /**
     * Scanning reads a good part of the shop's ledger. The page asks for
     * the list several times over one render — the badge, the header
     * actions, each section — so it is scanned once and held for the
     * request.
     *
     * @var Collection<int, SystemIssue>|null
     */
    private ?Collection $scanned = null;

    /** @var Collection<string, IssueAcknowledgement>|null */
    private ?Collection $answers = null;

    /** @return Collection<int, SystemIssue> */
    public function getIssues(): Collection
    {
        return $this->scanned ??= app(IssueScanner::class)->scan();
    }

    /** @return Collection<string, IssueAcknowledgement> */
    private function answers(): Collection
    {
        return $this->answers ??= IssueAcknowledgement::with('acknowledgedBy')
            ->get()
            ->keyBy('issue_key');
    }

    /**
     * The ones still wanting attention: never answered, or answered when
     * they were smaller than they are now.
     *
     * @return Collection<int, SystemIssue>
     */
    public function getOpenIssues(): Collection
    {
        return $this->getIssues()->reject(
            fn (SystemIssue $i) => $this->answers()->get($i->key)?->stillCovers($i) ?? false
        )->values();
    }

    /** @return Collection<int, SystemIssue> */
    public function getAnsweredIssues(): Collection
    {
        return $this->getIssues()->filter(
            fn (SystemIssue $i) => $this->answers()->get($i->key)?->stillCovers($i) ?? false
        )->values();
    }

    public function answerFor(SystemIssue $issue): ?IssueAcknowledgement
    {
        return $this->answers()->get($issue->key);
    }

    /**
     * How much worse it got since it was answered, as a percentage —
     * shown on an issue that has come back, so the owner can see why.
     */
    public function growthFor(SystemIssue $issue): ?int
    {
        $growth = $this->answers()->get($issue->key)?->growthSince($issue);

        return $growth !== null && $growth > 0 ? (int) round($growth * 100) : null;
    }

    public function getFixableCount(): int
    {
        return $this->getOpenIssues()->filter->isAutoFixable()->count();
    }

    /**
     * The badge is built from a full scan, and the sidebar is on every
     * page in the panel — so without this it would be 320 queries and
     * 390ms added to opening anything, roughly doubling a panel page.
     *
     * The scan reads a day's ledger; none of it turns over second by
     * second. The issue centre itself never reads this — that page
     * derives its lists fresh, so what the owner is looking at while he
     * acts is always current — and answering or reopening drops the
     * entry, so the badge follows him at once rather than in five
     * minutes.
     *
     * @return array{count: int, color: ?string}
     */
    private static function badge(): array
    {
        return Cache::remember(self::badgeKey(), now()->addMinutes(self::BADGE_MINUTES), function () {
            $open = app(static::class)->getOpenIssues();

            return [
                'count' => $open->count(),
                'color' => match (true) {
                    $open->contains(fn (SystemIssue $i) => $i->severity === SystemIssue::CRITICAL) => 'danger',
                    $open->isEmpty() => null,
                    default => 'warning',
                },
            ];
        });
    }

    private static function badgeKey(): string
    {
        // Per shop: two bakeries on one install must not read each
        // other's count.
        return 'issue-badge:'.(CurrentBakery::id() ?? 0);
    }

    public static function forgetBadge(): void
    {
        Cache::forget(self::badgeKey());
    }

    /**
     * Only open issues count — a badge that includes decided ones never
     * goes away, and a badge that never goes away is not read.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = self::badge()['count'];

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::badge()['color'];
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

    /**
     * «می‌دانم، تصمیمم همین است» — with a reason, because an answer nobody
     * explained is indistinguishable six months later from someone having
     * clicked it to make the red go away.
     */
    public function acknowledgeAction(): Action
    {
        return Action::make('acknowledge')
            ->label('می‌دانم، تصمیمم همین است')
            ->icon('heroicon-o-hand-thumb-up')
            ->color('gray')
            ->size('sm')
            ->modalHeading('پاسخ به این مورد')
            ->modalDescription(
                'این مورد از فهرست باز بیرون می‌رود و در فهرست «تصمیم گرفته‌شده»'
                .' همین صفحه می‌ماند. اگر مشکل بزرگ‌تر شود دوباره برمی‌گردد.'
            )
            ->form([
                Textarea::make('note')
                    ->label('چرا؟')
                    ->placeholder('مثلاً: حقوق بیرون از سامانه پرداخت می‌شود.')
                    ->helperText('برای خودتان و هرکسی که بعداً این صفحه را باز می‌کند.')
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('ثبت پاسخ')
            ->action(function (array $arguments, array $data): void {
                $issue = $this->getIssues()->firstWhere('key', $arguments['key'] ?? null);

                if (! $issue) {
                    return;
                }

                IssueAcknowledgement::updateOrCreate(
                    ['issue_key' => $issue->key],
                    [
                        'title' => $issue->title,
                        'severity' => $issue->severity,
                        'note' => $data['note'] ?: null,
                        'magnitude' => $issue->magnitude,
                        'acknowledged_by' => auth()->id(),
                    ],
                );

                $this->answers = null;
                self::forgetBadge();

                Notification::make()
                    ->title('ثبت شد')
                    ->body($issue->title.' دیگر در فهرست باز شمرده نمی‌شود.')
                    ->success()
                    ->send();
            });
    }

    public function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('دوباره یادآوری کن')
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->size('sm')
            ->action(function (array $arguments): void {
                IssueAcknowledgement::where('issue_key', $arguments['key'] ?? '')->delete();

                $this->answers = null;
                self::forgetBadge();

                Notification::make()
                    ->title('به فهرست باز برگشت')
                    ->success()
                    ->send();
            });
    }

    private function applyAutoFixes(): void
    {
        $applied = [];

        foreach ($this->getOpenIssues()->filter->isAutoFixable() as $issue) {
            $applied[] = ($issue->autoFix)();
        }

        if ($applied === []) {
            Notification::make()
                ->title('مورد قابل اصلاحی نبود')
                ->warning()
                ->send();

            return;
        }

        // The fix changed the data the list was built from.
        $this->scanned = null;
        self::forgetBadge();

        Notification::make()
            ->title(count($applied).' مورد اصلاح شد')
            ->body(implode(' — ', $applied))
            ->success()
            ->persistent()
            ->send();
    }
}
