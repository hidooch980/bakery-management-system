<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/** One payslip taking back part of one lot of bread a worker took home. */
class SalaryBreadRecovery extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'salary_payment_id',
        'sale_id',
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

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
