<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One bank SMS as it arrived, and what became of it.
 *
 * pending   — nothing on the books matches yet; waits for «ثبت» or «نادیده».
 * ambiguous — more than one existing movement could be it; someone picks.
 * matched   — already on the books (a salary, a purchase, an instalment…);
 *             linked, never recorded a second time.
 * confirmed — recorded from this SMS.
 * ignored   — not the shop's business.
 */
class BankSms extends Model
{
    use BelongsToBakery;

    protected $table = 'bank_sms';

    public const OPEN = ['pending', 'ambiguous'];

    protected $fillable = [
        'user_id', 'sender', 'bank', 'body', 'received_at', 'hash',
        'direction', 'kind', 'amount', 'balance', 'last4', 'bank_account_id',
        'suggested_category', 'status', 'bank_transaction_id',
        'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'decided_at' => 'datetime',
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    /** Same sender, same words, same minute: the same message twice. */
    public static function hashOf(string $sender, string $body, $receivedAt): string
    {
        $minute = Carbon::parse($receivedAt)->format('Y-m-d H:i');

        return hash('sha256', trim($sender)."\n".trim($body)."\n".$minute);
    }

    public function transaction()
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
