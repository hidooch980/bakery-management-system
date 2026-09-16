<?php

namespace App\Console\Commands;

use App\Models\StaffAdvance;

/** Advances filed against the drawer. See {@see MovesOffTheTill}. */
class MoveAdvancesOffTheTill extends MovesOffTheTill
{
    protected $signature = 'advances:off-the-till {--apply : جابه‌جا کن، نه فقط نشان بده}';

    protected $description = 'مساعده‌هایی که اشتباهی روی صندوق نشسته‌اند را به حساب سفید می‌برد';

    protected function modelClass(): string
    {
        return StaffAdvance::class;
    }

    protected function noun(): string
    {
        return 'مساعده';
    }
}
