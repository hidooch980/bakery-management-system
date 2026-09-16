<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\StaffAdvance;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Advances that were filed against the drawer because of a wrong guess.
 *
 * An advance with no account named used to post nowhere at all — money
 * left the shop and the books still had it. That was fixed by treating a
 * blank as «from the till», on the strength of a sentence in the panel
 * form: «خالی بگذارید اگر از صندوق پرداخت شده». Nobody had confirmed it.
 * The owner says advances come out of the bank, and he is the one who
 * hands over the notes.
 *
 * The rule is corrected now, but a posting is only rebuilt when its record
 * is saved, so the rows written under the wrong rule are still sitting on
 * the drawer. This moves them.
 *
 * Only the ones that were guessed at: a blank account, posted to the till.
 * An advance somebody deliberately marked as paid from the drawer names
 * that account on its own row and is left exactly where it is.
 *
 * Shows and changes nothing unless told to. This moves real money between
 * real accounts in a shop that is open, and a command that does that the
 * moment it is typed is one nobody can safely try.
 */
class MoveAdvancesOffTheTill extends Command
{
    protected $signature = 'advances:off-the-till {--apply : جابه‌جا کن، نه فقط نشان بده}';

    protected $description = 'مساعده‌هایی که اشتباهی روی صندوق نشسته‌اند را به حساب سفید می‌برد';

    public function handle(): int
    {
        $till = BankAccount::cashBox();
        $bank = BankAccount::mainBank();

        if (! $till) {
            $this->info('صندوقی تعیین نشده — چیزی برای جابه‌جا کردن نیست.');

            return self::SUCCESS;
        }

        if (! $bank) {
            $this->error('حساب بانکی‌ای پیدا نشد که مساعده‌ها به آن بروند.');
            $this->line('یک حساب غیرِصندوق را پیش‌فرض کنید، بعد دوباره بزنید.');

            return self::FAILURE;
        }

        $stranded = StaffAdvance::with('user')
            ->whereNull('bank_account_id')
            ->get()
            ->filter(fn (StaffAdvance $advance) => $advance->bankTransactions()
                ->where('bank_account_id', $till->id)
                ->exists());

        if ($stranded->isEmpty()) {
            $this->info('هیچ مساعده‌ای روی صندوق نمانده.');

            return self::SUCCESS;
        }

        $this->table(
            ['شناسه', 'کارمند', 'تاریخ', 'مبلغ'],
            $stranded->map(fn (StaffAdvance $advance) => [
                $advance->id,
                $advance->user?->name ?? '—',
                (string) ($advance->paid_on ?? '—'),
                Money::format((float) $advance->amount),
            ])->all(),
        );

        $total = Money::format((float) $stranded->sum('amount'));

        $this->line("از «{$till->title}» برداشته و روی «{$bank->title}» می‌نشیند: {$total}");

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('چیزی جابه‌جا نشد. برای انجامش: php artisan advances:off-the-till --apply');

            return self::SUCCESS;
        }

        // Rebuilt through the model rather than by editing the ledger row:
        // the posting is derived from the record, and a hand-moved row is
        // one the next save would put back where it was.
        $stranded->each->syncBankTransaction();

        $this->newLine();
        $this->info("{$stranded->count()} مساعده جابه‌جا شد.");
        $this->line('موجودی صندوق و حساب سفید را یک بار نگاه کنید.');

        return self::SUCCESS;
    }
}
