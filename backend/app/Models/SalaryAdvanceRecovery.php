<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One payslip taking back part of one advance. */
class SalaryAdvanceRecovery extends Model
{
    protected $fillable = [
        'salary_payment_id',
        'staff_advance_id',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function salaryPayment()
    {
        return $this->belongsTo(SalaryPayment::class);
    }

    public function advance()
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id');
    }
}
