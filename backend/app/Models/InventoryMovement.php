<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use BelongsToBakery;

    public const REASONS = [
        'manual' => 'ثبت دستی',
        'purchase' => 'خرید',
        'production' => 'مصرف در تولید',
        'spray' => 'آرد پاششی',
        'waste' => 'ضایعات',
        // Both of these were written by the application and left out of
        // this list, so twelve flour sales and five opening balances have
        // been showing the owner the raw English key wherever a movement
        // is named. It went unseen until «آرد کجا رفت» put the reasons in
        // a column of their own and one line read «flour_sale».
        'flour_sale' => 'فروش آرد',
        // Stock that was found to be there, or not there, outside any
        // record — an opening balance, or a figure repaired by hand.
        // «شمارش انبار» below is the narrower case where somebody counted.
        'correction' => 'اصلاح موجودی',
        'consignment_in' => 'دریافت امانی',
        'consignment_out' => 'تحویل امانی',
        // Written when a production entry or flour sale is deleted, so the
        // stock it moved comes back with the reason visible in the ledger.
        'production_reversal' => 'ابطال ثبت تولید',
        'flour_sale_reversal' => 'ابطال فروش آرد',
        // Written when consignment flour is handed back, or the record of it
        // is deleted — either way the sack physically moves the other way.
        'consignment_return' => 'بازگشت آرد امانی',
        // What the shelf actually held on a day somebody counted it.
        //
        // Deliberately its own reason rather than «ثبت دستی» or a nameless
        // correction: the shop once carried a 702,926,025 Rial expense row
        // titled «اختلاف» that existed only to make the books balance, and
        // it hid 57% of the shop's apparent costs. A line that says what it
        // is can be argued with. A line that says nothing cannot.
        'stocktake' => 'شمارش انبار',
    ];

    protected $fillable = [
        'inventory_item_id',
        'user_id',
        'direction',
        'quantity',
        'reason',
        'source_type',
        'source_id',
        'note',
        'reverses_movement_id',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /** The movement this one undoes, when it is a reversal. */
    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }
}
