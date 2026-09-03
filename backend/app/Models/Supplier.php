<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody the shop buys from.
 *
 * The mill, the salt merchant, the lorry driver who unloads. Until now
 * none of them existed in the system: a delivery was an expense row whose
 * title happened to mention a name, so «چقدر به کارخانه بدهکاریم» could
 * only be answered by the mill's own book.
 *
 * Deactivated rather than deleted once they have traded with the shop —
 * the history is what makes the balance mean anything, and a supplier
 * whose invoices are gone is a supplier the shop cannot be audited on.
 * The panel resource and the API's destroy both refuse to remove
 * one that has traded.
 */
class Supplier extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'name',
        'phone',
        'kind',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * What the shop still owes: everything invoiced, less what was handed
     * over at the door, less what has been paid on account since.
     *
     * Negative means the shop is in credit with them — paid ahead, or a
     * returned delivery that was already settled. It is shown rather than
     * clamped to zero, because a mill holding the shop's money is a fact
     * the owner should be able to see.
     */
    public function getBalanceAttribute(): float
    {
        $invoiced = (float) $this->purchases()->sum('amount');
        $atTheDoor = (float) $this->purchases()->sum('paid_amount');
        $onAccount = (float) $this->payments()->sum('amount');

        return round($invoiced - $atTheDoor - $onAccount, 2);
    }

    public function getBalanceFormattedAttribute(): string
    {
        return Money::format($this->balance);
    }

    public function getIsSettledAttribute(): bool
    {
        return abs($this->balance) < 0.01;
    }
}
