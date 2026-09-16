<?php

namespace App\Console\Commands;

use App\Models\SettlementRequest;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Settlements that closed an account for less than it owed.
 *
 * Until today, a settlement request that named no `amount` was taken to
 * mean the whole account — however little the seller said they were
 * handing over beside it. So a seller who owed ۴۵۰ and counted out ۴۰۰
 * had the whole account closed: no credit, no remaining debt, and nothing
 * on any page to say it had happened.
 *
 * The rule is corrected, but a settlement already confirmed stays
 * confirmed. This finds the ones that closed with a gap.
 *
 * It reports and never changes anything. Reopening a seller's account
 * from months ago is not a thing a command should decide — some of these
 * gaps are bread that cleared the debt without money changing hands
 * («منزل», «مدارس», «خیرات»), which is legitimate and is named on the row
 * itself. Those are separated out here rather than guessed at, but the
 * remainder is still a conversation with a person, not a migration.
 */
class FindForgivenSettlements extends Command
{
    protected $signature = 'settlements:forgiven';

    protected $description = 'تسویه‌هایی که حساب را با مبلغی کمتر از بدهی بسته‌اند را نشان می‌دهد';

    /** Payment types that clear a debt without any money arriving. */
    private const WITHOUT_MONEY = ['home', 'schools', 'charity', 'waste', 'credit'];

    public function handle(): int
    {
        $rows = [];
        $total = 0.0;

        SettlementRequest::with('user')
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at')
            ->chunk(200, function ($requests) use (&$rows, &$total) {
                foreach ($requests as $request) {
                    $gap = $this->gapOn($request);

                    if ($gap <= 0.01) {
                        continue;
                    }

                    $rows[] = [
                        $request->id,
                        $request->user?->name ?? '—',
                        (string) ($request->confirmed_at?->toDateString() ?? '—'),
                        Money::format((float) $request->amount),
                        Money::format((float) $request->paid_cash + (float) $request->paid_card),
                        Money::format($gap),
                    ];

                    $total += $gap;
                }
            });

        if ($rows === []) {
            $this->info('هیچ تسویه‌ای با کسری بسته نشده.');

            return self::SUCCESS;
        }

        $this->table(
            ['شناسه', 'فروشنده', 'تاریخ', 'بدهی بسته‌شده', 'تحویل داده', 'اختلاف'],
            $rows,
        );

        $this->newLine();
        $this->warn('جمع اختلاف: '.Money::format($total));
        $this->line('این‌ها حساب‌هایی‌اند که با مبلغی کمتر از بدهی بسته شده‌اند.');
        $this->line('نانی که بدون پول رفته (منزل، مدارس، خیرات) از این جمع کنار گذاشته شده.');
        $this->newLine();
        $this->line('چیزی تغییر نکرد — و این دستور چیزی را تغییر نمی‌دهد.');
        $this->line('هر ردیف یک گفت‌وگو با یک آدم است، نه یک اصلاحِ خودکار.');

        return self::SUCCESS;
    }

    /**
     * What the account was closed for, less everything that legitimately
     * closed it.
     *
     * Money handed over, plus any line on the breakdown that clears a debt
     * without money arriving. A settlement made entirely of bread taken
     * home is not a gap and must not be reported as one — telling the
     * owner his sellers are short when they are not is the fastest way to
     * make him stop reading the page.
     */
    private function gapOn(SettlementRequest $request): float
    {
        $covered = (float) $request->paid_cash + (float) $request->paid_card;

        foreach ((array) ($request->paid_breakdown ?? []) as $type => $amount) {
            if (in_array($type, self::WITHOUT_MONEY, true)) {
                $covered += (float) $amount;
            }
        }

        return round((float) $request->amount - $covered, 2);
    }
}
