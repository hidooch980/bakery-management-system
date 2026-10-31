<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every automatic message, sent or not, with the number masked. */
class SmsLog extends Model
{
    use BelongsToBakery;

    public const KIND_LABELS = [
        'partner_weekly' => 'خلاصهٔ هفتگی شریک',
        'partner_manual' => 'ارسال دستی به شریک',
        'staff_penalty' => 'جریمه/اخطار پرسنل',
        'staff_penalty_cancelled' => 'لغو جریمه/اخطار',
    ];

    public const STATUS_LABELS = [
        'sent' => 'ارسال شد',
        'failed' => 'ناموفق',
        'skipped' => 'ارسال نشد',
    ];

    protected $fillable = [
        'kind', 'recipient_name', 'phone_masked', 'message', 'channel', 'status', 'error', 'sent_by',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** ۰۹۱۲***۴۵۶۷ — enough to tell numbers apart, not enough to dial. */
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $d = preg_replace('/\D/', '', $phone);
        if (strlen($d) < 7) {
            return str_repeat('*', strlen($d));
        }

        return substr($d, 0, 4).str_repeat('*', strlen($d) - 8).substr($d, -4);
    }
}
