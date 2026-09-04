<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A tanker's worth of diesel arriving.
 *
 * Deliveries rather than consumption, deliberately: what was dropped is a
 * figure the shop reads off a docket, where litres burned per hour is a
 * guess dressed up as a measurement.
 */
class DieselDelivery extends Model
{
    use BelongsToBakery, RecordsAudit;

    protected $fillable = [
        'user_id',
        'received_on',
        'litres',
        'amount',
        'docket_number',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'litres' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Null when the delivery came off quota and carried no invoice. */
    public function getWasPaidForAttribute(): bool
    {
        return $this->amount !== null && (float) $this->amount > 0;
    }

    public function getAmountFormattedAttribute(): string
    {
        return $this->was_paid_for
            ? Money::format((float) $this->amount)
            : 'سهمیه‌ای';
    }

    public function getReceivedOnDisplayAttribute(): string
    {
        return AppCalendar::date($this->received_on);
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return 'گازوئیل '.$this->litres.' لیتر';
    }
}
