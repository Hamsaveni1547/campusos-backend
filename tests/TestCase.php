<?php

namespace Tests;

use App\Models\User;
use App\Modules\Identity\Actions\Roles\SyncSystemPermissions;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Services\RoleSynchronizer;
use App\Modules\Institution\Models\Institution;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected bool $syncPermissions = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->syncPermissions && in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            app(SyncSystemPermissions::class)->handle();
        }
    }

    protected function institution(array $attributes = []): Institution
    {
        return Institution::factory()->create($attributes);
    }

    /**
     * Create a member of an institution holding the given system role names.
     *
     * @param  array<int, string>  $roles
     * @param  array<string, mixed>  $attributes
     */
    protected function member(Institution $institution, array $roles = [], array $attributes = [], bool $withoutMfaRequirement = true): User
    {
        $user = User::factory()->create($attributes);
        $user->institutions()->attach($institution->id, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);

        foreach ($roles as $roleName) {
            $this->grantRole($user, $institution, $roleName);
        }

        if ($withoutMfaRequirement) {
            $this->setting($institution, 'security', ['mfa_required_roles' => []]);
        }

        return $user;
    }

    protected function grantRole(User $user, Institution $institution, string $roleName, ?int $departmentId = null): RoleAssignment
    {
        $role = Role::query()->where('name', $roleName)->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution->id))->firstOrFail();

        $assignment = RoleAssignment::query()->withoutGlobalScopes()->create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'department_id' => $departmentId,
        ]);

        app(RoleSynchronizer::class)->sync($user, $institution->id);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function setting(?Institution $institution, string $group, array $values): void
    {
        app(SettingsRepository::class)->update($group, $values, $institution?->id, $institution === null);
    }

    /**
     * Sign in through the real login endpoint and return the bearer token.
     */
    protected function login(User $user, string $password = UserFactory::PASSWORD, string $device = 'PHPUnit'): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => $password,
            'device_name' => $device,
        ])->assertOk();

        $this->assertFalse($response->json('data.mfa_required'), 'Expected login without MFA.');

        return (string) $response->json('data.access_token');
    }

    /**
     * Call the API as a user (real token, real middleware).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, User $user, array $data = [], array $headers = []): TestResponse
    {
        $token = $this->tokens[$user->id] ??= $this->login($user);

        // Forget the previous request's authenticated user, as a new request would.
        app(CurrentInstitution::class)->set(null);
        auth()->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders(array_merge(['Authorization' => 'Bearer '.$token], $headers))
            ->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    /**
     * @var array<int, string>
     */
    protected array $tokens = [];
}
