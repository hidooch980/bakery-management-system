<?php

namespace App\Console\Commands;

use App\Models\SalaryPayment;
use Illuminate\Database\Eloquent\Model;

/**
 * Wages filed against the drawer. See {@see MovesOffTheTill}.
 *
 * The same wrong guess as the advances, found a day later and only
 * because the question was asked out loud: «حقوق و مزایا حساب سفید».
 */
class MoveSalariesOffTheTill extends MovesOffTheTill
{
    protected $signature = 'salaries:off-the-till {--apply : جابه‌جا کن، نه فقط نشان بده}';

    protected $description = 'فیش‌های حقوقی که اشتباهی روی صندوق نشسته‌اند را به حساب سفید می‌برد';

    protected function modelClass(): string
    {
        return SalaryPayment::class;
    }

    protected function noun(): string
    {
        return 'فیش';
    }

    protected function noneLeftMessage(): string
    {
        return 'هیچ فیشی روی صندوق نمانده.';
    }

    /** A payslip's money is its net pay, not a single `amount` column. */
    protected function amountOf(Model $record): float
    {
        return (float) $record->net_amount;
    }
}
