<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Support\StockReversal;
use Illuminate\Database\Eloquent\Model;

class ChaneEntry extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'dough_entry_id',
        'user_id',
        'chane_count',
        'tray_count',
        'tray_counts',
        'normal_weight_kg',
        'nanino_weight_kg',
        'spray_flour_kg',
        'status',
    ];

    protected function casts(): array
    {
        return ['tray_counts' => 'array'];
    }

    protected static function booted(): void
    {
        // The sales table cascades on this entry's key, so a bare delete
        // would erase the sales inside the database where no model hook
        // runs — leaving their bank postings in the account with nothing
        // behind them. Deleting them here first keeps every reversal on
        // the model path. (This happened: the 06/06 batch was deleted, its
        // card sale vanished by cascade, and 7,290,000 Rial sat in the
        // account with no record to explain it.)
        static::deleting(function (self $entry) {
            $entry->sales()->get()->each->delete();
        });

        // Shaping consumed dough and spray flour, so deleting the entry
        // gives both back and frees the batch to be shaped again —
        // otherwise the dough stays spent and the batch stays stuck as
        // processed with nothing to show for it.
        static::deleted(function (self $entry) {
            StockReversal::of($entry, 'ابطال ثبت چانه');

            $entry->doughEntry?->update(['status' => 'pending']);
        });
    }

    /** "۳۰ + ۳۰ + ۱۲" — how the batch was actually counted out. */
    public function getTrayBreakdownAttribute(): ?string
    {
        return empty($this->tray_counts)
            ? null
            : implode(' + ', $this->tray_counts);
    }

    public function doughEntry()
    {
        return $this->belongsTo(DoughEntry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    /** A batch can carry several sales — card, cash, home, charity. */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * The weight that counts for stock, sales and reporting.
     *
     * Only the normal chane is real output; the nanino figure is recorded
     * for comparison and must stay out of every calculation.
     */
    public function getWeightKgAttribute(): float
    {
        return round((float) $this->normal_weight_kg, 3);
    }
}
