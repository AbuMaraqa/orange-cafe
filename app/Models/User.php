<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    /**
     * The route name of this user's home interface, based on their first role.
     * Falls back to the default role's home route if they have none assigned.
     */
    public function homeRouteName(): string
    {
        $roleName = $this->getRoleNames()->first() ?? config('roles.default');

        return config("roles.available.{$roleName}.home_route", 'dashboard');
    }

    /**
     * Every movement on this person's account, oldest first.
     *
     * Staff are Users here — there is no separate employee table, and
     * adding one would mean maintaining two records of the same person and
     * deciding which of them a shift belongs to. The financial side hangs
     * off the account they already log in with.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StaffLedgerEntry::class)->chronological();
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /** Expenses recorded *against* this person, not ones they entered. */
    public function staffExpenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * What this person owes the cafe, in cents. Negative means the cafe
     * owes them. See StaffLedgerEntry for the sign convention.
     *
     * Summed rather than stored on the row: a cached balance is a second
     * copy of the truth, and the first time an entry is edited without
     * going through the one method that maintains it, the two disagree
     * with nothing on screen to say which is right.
     */
    public function staffBalanceCents(): int
    {
        return (int) $this->ledgerEntries()->sum('amount_cents');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
