<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\HasATwin;
use App\Models\Concerns\PostsToBankAccount;
use App\Models\Concerns\RecordsAudit;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToBakery, HasATwin, PostsToBankAccount, RecordsAudit;

    /**
     * Order matters: this is the order the picker offers them in, so the
     * costs a bakery pays most often come first.
     */
    public const CATEGORIES = [
        'freight' => 'حمل و نقل',
        'unloading' => 'تخلیه و باربری',
        'fuel' => 'سوخت',
        'utilities' => 'آب، برق، گاز',
        'rent' => 'اجاره',
        'maintenance' => 'تعمیرات',
        'equipment' => 'تجهیزات',
        'packaging' => 'بسته‌بندی',
        'insurance' => 'بیمه',
        'tax' => 'مالیات و عوارض',
        'salary' => 'حقوق کارکنان',
        'other' => 'سایر',
    ];

    /**
     * What the shop used to file a delivery under, before a delivery
     * became a record of its own.
     *
     * Buying flour was an expense row here, an inventory movement
     * somewhere else and a dated price in a third place, with nothing
     * joining them and no name on any of it. A purchase invoice is now
     * all three at once, so these three are no longer offered.
     *
     * They are kept rather than deleted for two reasons, and both matter:
     * the rows already on file have to keep reading in Persian, and the
     * profit statement has to keep counting them. Rewriting history to
     * tidy a list is how a month's costs go missing.
     *
     * An old row can still be corrected — see the update rule in
     * ExpenseController — but nothing new lands here.
     */
    public const RETIRED_CATEGORIES = [
        'flour' => 'خرید آرد',
        'salt' => 'خرید نمک',
        'dough' => 'خرید خمیر',
    ];

    /** Everything a category key could be, offered or retired. */
    public static function categoryLabels(): array
    {
        return self::CATEGORIES + self::RETIRED_CATEGORIES;
    }

    protected $fillable = [
        'user_id',
        'category',
        'title',
        'amount',
        'spent_on',
        'bank_account_id',
        'note',
    ];

    /**
     * What makes two expenses the same payment: the same category, on the
     * same day. The amount is compared by the trait.
     *
     * Not the title: «گازوئیل» and «گازوییل» are the same payment typed
     * twice, and matching on the words would let the second through.
     */
    /**
     * Wages are excluded, and only wages.
     *
     * Two bakers on the same pay, settled the same afternoon, are two
     * correct rows — and that happens every payday, for every pair paid
     * alike. Asking there would teach the owner to answer «بله، واقعاً دو
     * بار» without looking, and the answer would come just as quickly on
     * the diesel that really was typed twice.
     *
     * Freight and unloading are not excluded: they repeat far less often
     * and they are exactly what gets copied off a receipt at night.
     */
    protected function looksForATwin(): bool
    {
        return $this->category !== 'salary';
    }

    protected function twinScope(Builder $query): void
    {
        $query->where('category', $this->category)
            ->whereDate('spent_on', $this->spent_on);
    }

    /** One line naming this expense, for a message about it. */
    public function describe(): string
    {
        return '#'.$this->id.' — '.$this->title
            .'، '.AppCalendar::date($this->spent_on)
            .'، '.Money::format((float) $this->amount);
    }

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::categoryLabels()[$this->category] ?? $this->category;
    }

    public function getSpentOnJalaliAttribute(): ?string
    {
        return Jalali::date($this->spent_on);
    }

    // ------------------------------------------------- bank posting

    public function bankPostingAccountId(): ?int
    {
        return $this->bank_account_id;
    }

    public function bankPostingAmount(): float
    {
        return (float) $this->amount;
    }

    public function bankPostingReason(): string
    {
        return 'expense';
    }

    public function bankPostingDate()
    {
        return $this->spent_on ?? now();
    }
}
