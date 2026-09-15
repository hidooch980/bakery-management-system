<?php

namespace App\Models;

use App\Support\CurrentBakery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'is_active',
        'monthly_salary',
        'bakery_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'monthly_salary' => 'decimal:2',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The shop this person works at.
     *
     * Deliberately without the global scope every other model carries:
     * resolving the signed-in user is how the current bakery is worked out
     * in the first place, so scoping the user by it would ask the question
     * to answer the question. Listings that must not cross shops say so
     * themselves, through [scopeOfCurrentBakery].
     */
    public function bakery()
    {
        return $this->belongsTo(Bakery::class);
    }

    /**
     * The other shops this person may reach, beside their own.
     *
     * Their own is `bakery_id` and is not listed here: it is where they
     * work and it cannot be taken away by deleting a row. Somebody with
     * nothing in this table reaches exactly one shop, which is every
     * member of staff and the shape the system had before owners with
     * more than one shop existed.
     */
    public function bakeries()
    {
        return $this->belongsToMany(Bakery::class);
    }

    /**
     * Every shop this person may look at, their own first.
     *
     * Ordered with home at the front because that is the one they want
     * nine times in ten, and a switcher that opens on somebody else's
     * shop is a switcher people learn to distrust.
     *
     * @return Collection<int, Bakery>
     */
    public function reachableBakeries()
    {
        $extra = $this->bakeries()->orderBy('name')->get();
        $home = $this->bakery;

        return $home === null
            ? $extra
            : collect([$home])->concat($extra->reject->is($home))->values();
    }

    /**
     * Whether this person may act as the given shop.
     *
     * Asked before any switch is honoured. An id that is merely *sent* is
     * a request, not a permission — without this check, a header would be
     * enough to read another shop's money.
     */
    public function canReachBakery(?int $bakeryId): bool
    {
        if ($bakeryId === null) {
            return false;
        }

        if ((int) $this->bakery_id === $bakeryId) {
            return true;
        }

        return $this->bakeries()->whereKey($bakeryId)->exists();
    }

    public function scopeOfCurrentBakery($query)
    {
        $bakeryId = CurrentBakery::id();

        return $bakeryId === null ? $query : $query->where('bakery_id', $bakeryId);
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->bakery_id ??= CurrentBakery::id();
        });
    }

    public function doughEntries()
    {
        return $this->hasMany(DoughEntry::class);
    }

    public function chaneEntries()
    {
        return $this->hasMany(ChaneEntry::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function salaryPayments()
    {
        return $this->hasMany(SalaryPayment::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasRole('admin');
    }
}
