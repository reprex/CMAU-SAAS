<?php

namespace App\Models;

use App\Support\Divisions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Staff login. Access is set per business division and for the shared modules (docs/divisions.md):
 * view, staff (can change) or admin (the division's admin screens; shared admin manages users).
 * `role` is the account's original single role, used only until `divisions` is set.
 */
#[Fillable(['name', 'email', 'password', 'role', 'active', 'divisions'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = ['admin', 'sales', 'readonly'];

    protected $attributes = ['role' => 'sales', 'active' => true];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'divisions' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** view, staff or admin in a division (or "shared"); null = no access. */
    public function access(string $division): ?string
    {
        if (is_array($this->divisions)) {
            $v = $this->divisions[$division] ?? null;

            return array_key_exists((string) $v, config('divisions.levels')) ? $v : null;
        }
        // Not set yet: the original role. Admins everywhere; sales and read-only in CrowdMerch and the shared modules.
        $level = ['admin' => 'admin', 'sales' => 'staff'][$this->role] ?? 'view';

        return $this->role === 'admin' || in_array($division, ['crowdmerch', Divisions::SHARED], true) ? $level : null;
    }

    public function canView(?string $division = null): bool
    {
        return $this->access($division ?? Divisions::current()) !== null;
    }

    /** Admin in the division being used (or the one named). */
    public function isAdmin(?string $division = null): bool
    {
        return $this->access($division ?? Divisions::current()) === 'admin';
    }

    public function canEdit(?string $division = null): bool
    {
        return in_array($this->access($division ?? Divisions::current()), ['staff', 'admin'], true);
    }

    /** The divisions this user can open. @return list<string> */
    public function divisionKeys(): array
    {
        return array_values(array_filter(Divisions::keys(), fn ($d) => $this->access($d) !== null));
    }

    /** The level shown next to the name: the division being viewed. */
    public function levelLabel(): string
    {
        return config('divisions.levels')[$this->access(Divisions::current()) ?? ''] ?? 'No access';
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
