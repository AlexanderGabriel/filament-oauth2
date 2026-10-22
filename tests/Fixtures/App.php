<?php

/*
 * The controller still references these classes of the Laravel app directly,
 * so the tests provide minimal versions of them.
 */

namespace App\Http\Controllers {
    abstract class Controller {}
}

namespace App\Models {
    use Filament\Models\Contracts\FilamentUser;
    use Filament\Panel;
    use Illuminate\Database\Eloquent\Model;
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
            return $this->belongsToMany(Role::class);
        }

        public function canAccessPanel(Panel $panel): bool
        {
            return static::$requiredRole === null || $this->roles->contains('name', static::$requiredRole);
        }
    }

    class Role extends Model
    {
        protected $guarded = [];

        public $timestamps = false;
    }
}
