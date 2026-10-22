<?php

namespace AlexanderGabriel\FilamentOauth2\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    protected $guarded = [];

    protected $casts = ['password' => 'hashed'];

    /**
     * Role needed to access the panel, null: everybody.
     */
    public static ?string $requiredRole = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return static::$requiredRole === null || $this->roles->contains('name', static::$requiredRole);
    }
}
