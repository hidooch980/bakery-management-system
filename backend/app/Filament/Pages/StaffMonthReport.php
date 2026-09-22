<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\StaffCost;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * A month's staff costs, on paper.
 *
 * «گزارش ماهانه قابل چاپ». The figures existed — on the payroll report,
 * on the attendance one, on each person's advances — but none of it went
 * anywhere a person could hold. The shop's payroll conversations happen
 * at the desk with a sheet between two people, and there was no sheet.
 *
 * Which is also why this is a panel page and not a screen in the phone
 * app: printing happens at the desk.
 *
 * The month is the Jalali one, because that is what a payslip's period
 * is. The quota's 5th→4th month is a different month for a different
 * purpose, and using it here would put a person's wages in the wrong one.
 */
class StaffMonthReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'گزارش ماهانهٔ کارکنان';

    protected static ?string $title = 'گزارش ماهانهٔ کارکنان';

    protected static ?string $slug = 'staff-month-report';

    protected static string $view = 'filament.pages.staff-month-report';

    /** Which month is on screen, as a Jalali Y/m. Empty means this one. */
    public ?string $month = null;

    public function mount(): void
    {
        $this->month ??= Jalali::format(now(), 'Y/m');
    }

    /** The month on screen, as a real date range. */
    public function range(): array
    {
        $start = Jalali::parse(($this->month ?: Jalali::format(now(), 'Y/m')).'/01')
            ?? now()->startOfMonth();

        return Jalali::monthRangeFor($start);
    }

    public function monthLabel(): string
    {
        [$from] = $this->range();

        return Jalali::monthLabel($from) ?? '';
    }

    /** The months a person might reasonably ask for, newest first. */
    public function monthOptions(): array
    {
        $options = [];

        for ($back = 0; $back < 13; $back++) {
            $when = now()->copy()->subMonths($back);
            $key = Jalali::format($when, 'Y/m');
            $options[$key] = Jalali::monthLabel($when) ?? $key;
        }

        return $options;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        [$from, $to] = $this->range();

        return User::ofCurrentBakery()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $person) => StaffCost::for($person, $from, $to))
            // Somebody with no pay, no advance and no lateness in the
            // month is not a line on a sheet the owner is going to hand
            // to somebody; they are a row of zeroes to scroll past.
            ->filter(fn (array $r) => $r['total'] > 0
                || $r['payslip_count'] > 0
                || $r['late_days'] > 0)
            ->values();
    }

    public function totalFormatted(): string
    {
        return Money::format((float) $this->rows()->sum('total'));
    }
}
