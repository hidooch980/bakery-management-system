<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/** Which automatic messages and alerts this shop has turned on. All start off. */
class NotificationSetting extends Model
{
    use BelongsToBakery;

    protected $fillable = [
        'sms_partner_weekly',
        'sms_staff_penalty',
        'seller_debt_threshold',
        'credit_reminder_enabled',
        'credit_reminder_days',
        'card_reader_check_enabled',
        'card_reader_tolerance',
    ];

    protected function casts(): array
    {
        return [
            'sms_partner_weekly' => 'boolean',
            'sms_staff_penalty' => 'boolean',
            'seller_debt_threshold' => 'decimal:2',
            'credit_reminder_enabled' => 'boolean',
            'credit_reminder_days' => 'integer',
            'card_reader_check_enabled' => 'boolean',
            'card_reader_tolerance' => 'decimal:2',
        ];
    }

    public static function current(): self
    {
        $row = static::query()->firstOrCreate([]);

        // A row just made holds only what was written; the defaults are
        // the database's, so read them back rather than see nulls.
        return $row->wasRecentlyCreated ? $row->refresh() : $row;
    }
}
