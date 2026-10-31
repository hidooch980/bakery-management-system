<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * One condition, checked against a seller's own records, that files a
 * warning for the manager to approve. Nothing is docked: a rule only ever
 * asks, through StaffPenaltyRequest, and the manager answers.
 */
class SellerWarningRule extends Model
{
    use BelongsToBakery, RecordsAudit;

    public const NANINO_URL = 'https://nanino.ir/Nanino-Kharidar/KharidarPdf.pdf';

    /** What each condition measures, and the unit its threshold is in. */
    public const CONDITIONS = [
        'debt_over' => ['label' => 'بدهی فروشنده بیش از سقف', 'unit' => 'money'],
        'late_settlement' => ['label' => 'تسویه نکردن بیش از چند روز', 'unit' => 'days'],
        'till_shortfall' => ['label' => 'کسری صندوق یا نان در روز', 'unit' => 'money'],
        'late_shift' => ['label' => 'تأخیر در شروع شیفت', 'unit' => 'minutes'],
        'off_queue_bulk' => ['label' => 'فروش عمده بیرون از کارتخوان در یک ثبت', 'unit' => 'breads'],
        'card_mismatch' => ['label' => 'فروش کارتخوان بیش از واریز بانک', 'unit' => 'money'],
    ];

    public const UNIT_LABELS = [
        'money' => 'مبلغ',
        'days' => 'روز',
        'minutes' => 'دقیقه',
        'breads' => 'عدد نان',
    ];

    /**
     * The rules a shop starts with, all switched off. Thresholds are
     * placeholders for the manager to set; a rule without one is skipped.
     */
    public const DEFAULTS = [
        'debt_over' => [
            'name' => 'بدهی بیش از سقف',
            'threshold' => null,
            'reason' => 'debt',
            'source' => 'custom',
            'source_url' => null,
            'description' => null,
        ],
        'late_settlement' => [
            'name' => 'تسویه دیرهنگام',
            'threshold' => 3,
            'reason' => 'late_settlement',
            'source' => 'custom',
            'source_url' => null,
            'description' => null,
        ],
        'till_shortfall' => [
            'name' => 'کسری صندوق',
            'threshold' => null,
            'reason' => 'till_shortfall',
            'source' => 'custom',
            'source_url' => null,
            'description' => null,
        ],
        'late_shift' => [
            'name' => 'عدم رعایت ساعت شیفت کاری (نانینو)',
            'threshold' => 15,
            'reason' => 'shift_hours',
            'source' => 'nanino',
            'source_url' => self::NANINO_URL,
            'description' => null,
        ],
        'off_queue_bulk' => [
            'name' => 'عرضه بیش از حد نان خارج از صف (نانینو)',
            'threshold' => 100,
            'reason' => 'off_queue',
            'source' => 'nanino',
            'source_url' => self::NANINO_URL,
            'description' => null,
        ],
        'card_mismatch' => [
            'name' => 'ثبت تراکنش غیر واقعی (نانینو)',
            'threshold' => null,
            'reason' => 'fake_transaction',
            'source' => 'nanino',
            'source_url' => self::NANINO_URL,
            'description' => null,
        ],
    ];

    protected $fillable = [
        'name', 'condition', 'threshold', 'reason', 'description', 'source', 'source_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Give the current shop any default rule it does not have yet. */
    public static function ensureDefaults(): void
    {
        $have = self::query()->pluck('condition')->all();
        foreach (self::DEFAULTS as $condition => $rule) {
            if (! in_array($condition, $have, true)) {
                self::create($rule + ['condition' => $condition, 'is_active' => false]);
            }
        }
    }

    public function getUnitAttribute(): string
    {
        return self::CONDITIONS[$this->condition]['unit'] ?? 'money';
    }

    public function getConditionLabelAttribute(): string
    {
        return self::CONDITIONS[$this->condition]['label'] ?? $this->condition;
    }

    public function isMoney(): bool
    {
        return $this->unit === 'money';
    }
}
