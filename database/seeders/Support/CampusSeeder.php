<?php

namespace Database\Seeders\Support;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\AssignRole;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Role;
use App\Modules\Institution\Models\Institution;
use App\Shared\Tenancy\CurrentInstitution;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Base for the demo seeders: runs inside the demo institution and offers small helpers.
 *
 * Every demo account signs in with DEMO_PASSWORD. Seeders go through the module services, so the
 * data follows the same rules (numbering, statuses, history) as data entered in the app.
 */
abstract class CampusSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Campus@Demo2026';

    public const INSTITUTION_CODE = 'BIT';

    public const DOMAIN = 'bit.campusos.test';

    abstract protected function seed(): void;

    public function run(): void
    {
        $institution = Institution::query()->where('code', self::INSTITUTION_CODE)->firstOrFail();

        app(CurrentInstitution::class)->runAs($institution, function (): void {
            $this->seed();
            Auth::forgetUser();
        });
    }

    protected function institution(): Institution
    {
        return app(CurrentInstitution::class)->require();
    }

    /**
     * Runs a callback signed in as the user, so created_by and similar fields name them.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function as(User $user, Closure $callback): mixed
    {
        $previous = Auth::user();
        Auth::setUser($this->fresh($user));

        try {
            return $callback();
        } finally {
            $previous instanceof User ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    /**
     * Makes an invited account usable at once with the demo password.
     */
    protected function activate(User $user): User
    {
        $user->forceFill([
            'password' => self::passwordHash(),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        return $user;
    }

    /**
     * The demo password hashed once; the model keeps an already-hashed value as it is.
     */
    protected static function passwordHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(self::DEMO_PASSWORD);
    }

    protected function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /**
     * A fresh copy, so role and permission lookups see the latest assignments.
     */
    protected function fresh(User $user): User
    {
        return User::query()->findOrFail($user->id);
    }

    protected function grant(User $user, string $roleName, ?int $departmentId = null): void
    {
        $role = Role::query()->whereNull('institution_id')->where('name', $roleName)->firstOrFail();

        if (! $user->roleAssignments()->effective()->where('role_id', $role->id)->exists()) {
            app(AssignRole::class)->handle($user, $role, null, departmentId: $departmentId, reason: 'Demo data');
        }
    }

    protected function admin(): User
    {
        return $this->user('admin@'.self::DOMAIN);
    }

    protected function email(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '.', trim($name)) ?? $name).'@'.self::DOMAIN;
    }
}
