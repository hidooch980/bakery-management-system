<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/** Which of the shop's accounts an SMS about «…۴۵۶۷» is about. */
class BankSmsAccount extends Model
{
    use BelongsToBakery;

    protected $fillable = ['last4', 'bank_account_id'];

    public function account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public static function accountFor(?string $last4): ?int
    {
        if ($last4 === null) {
            return null;
        }

        return self::query()->where('last4', $last4)->value('bank_account_id');
    }
}
