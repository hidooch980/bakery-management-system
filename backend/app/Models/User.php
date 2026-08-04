<?php

namespace App\Models;

use App\Support\CurrentBakery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
