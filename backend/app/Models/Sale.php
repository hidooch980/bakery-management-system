<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'chane_entry_id',
        'user_id',
        'payment_type',
        'bread_count',
        'customer_id',
        'amount',
        'note',
    ];

    public function chaneEntry()
    {
        return $this->belongsTo(ChaneEntry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
