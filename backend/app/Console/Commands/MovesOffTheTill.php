<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Payments filed against the drawer because of a wrong guess.
 *
 * A payment with no account named used to post nowhere at all — money left
 * the shop and the books still had it. That was fixed by treating a blank
 * as «from the till», on the strength of a sentence in the panel form:
 * «خالی بگذارید اگر از صندوق پرداخت شده». Nobody had confirmed it. The
 * owner, asked directly, says both advances and wages come out of the
 * bank, and he is the one who hands over the notes.
 *
 * The rule is corrected now, but a posting is only rebuilt when its record
 * is saved, so the rows written under the wrong rule are still sitting on
 * the drawer. This moves them.
 *
 * Only the ones that were guessed at: a blank account, posted to the till.
 * A payment somebody deliberately marked as paid from the drawer names
 * that account on its own row and is left exactly where it is.
 *
 * Shows and changes nothing unless told to. This moves real money between
 * real accounts in a shop that is open, and a command that does that the
 * moment it is typed is one nobody can safely try.
 */
abstract class MovesOffTheTill extends Command
{
    /** The model whose stranded rows this command moves. */
    abstract protected function modelClass(): string;

    /** What one row is called, for the table and the totals line. */
    abstract protected function noun(): string;

    /**
     * «none left» in words.
     *
     * Written out rather than glued together from the noun: Persian
     * attaches the indefinite differently to «مساعده» and to «فیش», and a
     * sentence assembled by the machine gets one of them wrong.
     */
    protected function noneLeftMessage(): string
    {
        return "هیچ {$this->noun()}‌ای روی صندوق نمانده.";
    }

    /** Rows with no account named, whose posting landed on the till. */
    protected function stranded(BankAccount $till): Collection
    {
        return $this->modelClass()::with('user')
            ->whereNull('bank_account_id')
            ->get()
            ->filter(fn (Model $record) => $record->bankTransactions()
                ->where('bank_account_id', $till->id)
                ->exists());
    }

    /** What to show in the row's date column. */
    protected function dateOf(Model $record): string
    {
        return (string) ($record->paid_on ?? '—');
    }

    /** What to show in the row's amount column, and sum for the total. */
    protected function amountOf(Model $record): float
    {
        return (float) $record->amount;
    }

    public function handle(): int
    {
        $till = BankAccount::cashBox();
        $bank = BankAccount::mainBank();
        $noun = $this->noun();

        if (! $till) {
            $this->info('صندوقی تعیین نشده — چیزی برای جابه‌جا کردن نیست.');

            return self::SUCCESS;
        }

        if (! $bank) {
            $this->error("حساب بانکی‌ای پیدا نشد که {$noun}‌ها به آن بروند.");
            $this->line('یک حساب غیرِصندوق را پیش‌فرض کنید، بعد دوباره بزنید.');

            return self::FAILURE;
        }

        $stranded = $this->stranded($till);

        if ($stranded->isEmpty()) {
            $this->info($this->noneLeftMessage());

            return self::SUCCESS;
        }

        $this->table(
            ['شناسه', 'کارمند', 'تاریخ', 'مبلغ'],
            $stranded->map(fn (Model $record) => [
                $record->id,
                $record->user?->name ?? '—',
                $this->dateOf($record),
                Money::format($this->amountOf($record)),
            ])->all(),
        );

        $total = Money::format($stranded->sum(fn (Model $r) => $this->amountOf($r)));

        $this->line("از «{$till->title}» برداشته و روی «{$bank->title}» می‌نشیند: {$total}");

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('چیزی جابه‌جا نشد. برای انجامش دوباره با --apply بزنید.');

            return self::SUCCESS;
        }

        // Rebuilt through the model rather than by editing the ledger row:
        // the posting is derived from the record, and a hand-moved row is
        // one the next save would put back where it was.
        $stranded->each->syncBankTransaction();

        $this->newLine();
        $this->info("{$stranded->count()} {$noun} جابه‌جا شد.");
        $this->line('موجودی صندوق و حساب سفید را یک بار نگاه کنید.');

        return self::SUCCESS;
    }
}
