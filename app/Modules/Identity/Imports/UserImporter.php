<?php

namespace App\Modules\Identity\Imports;

use App\Models\User;
use App\Modules\Identity\Actions\Users\CreateUser;
use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Models\Role;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Imports\Import;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRunContext;
use App\Shared\Tenancy\CurrentInstitution;

/**
 * CSV columns: name, email (required); mobile, username, roles (separated by "|").
 * New users receive an invitation email to set their password.
 */
class UserImporter implements Importer
{
    /**
     * @var array<string, Role|null>
     */
    private array $roleCache = [];

    public function __construct(
        private CreateUser $createUser,
        private CurrentInstitution $currentInstitution,
    ) {}

    public function permission(): string
    {
        return IdentityPermissions::UsersImport;
    }

    public function requiredColumns(): array
    {
        return ['name', 'email'];
    }

    public function optionalColumns(): array
    {
        return ['mobile', 'username', 'roles'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'username' => ['nullable', 'alpha_dash', 'min:3', 'max:64'],
            'roles' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function check(array $row, ImportRunContext $context): array
    {
        $errors = [];
        $institutionId = $this->currentInstitution->require()->id;

        foreach (['email', 'mobile', 'username'] as $column) {
            $firstRow = $context->remember($column, $row[$column] ?? null, 0);
            if ($firstRow !== null) {
                $errors[] = "The {$column} appears more than once in the file.";
            }
        }

        $email = mb_strtolower((string) ($row['email'] ?? ''));
        $existing = $email !== '' ? User::query()->where('email', $email)->first() : null;

        if ($existing !== null && $existing->belongsToInstitution($institutionId)) {
            $errors[] = 'A user with this email already exists in this institution.';
        }

        foreach (['mobile', 'username'] as $column) {
            $value = $row[$column] ?? null;

            if ($value !== null && User::query()->where($column, $value)->where('email', '!=', $email)->exists()) {
                $errors[] = "The {$column} is already used by another account.";
            }
        }

        foreach ($this->roleNames($row) as $name) {
            if ($this->role($name, $institutionId) === null) {
                $errors[] = "Unknown role \"{$name}\".";
            }
        }

        return $errors;
    }

    public function import(array $row, Import $import, User $actor): void
    {
        $institutionId = $this->currentInstitution->require()->id;
        $roles = [];

        foreach ($this->roleNames($row) as $name) {
            $role = $this->role($name, $institutionId);

            if ($role === null) {
                throw new BusinessRuleException("Unknown role \"{$name}\".");
            }

            $roles[] = $role;
        }

        if ($roles !== [] && ! $actor->can(IdentityPermissions::RoleAssignmentsManage)) {
            throw new BusinessRuleException('You do not have permission to assign roles.');
        }

        $this->createUser->handle([
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'mobile' => $row['mobile'] ?? null,
            'username' => $row['username'] ?? null,
        ], $actor, $roles, sendInvite: (bool) ($import->options['send_invite'] ?? true));
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<int, string>
     */
    private function roleNames(array $row): array
    {
        if (blank($row['roles'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', (string) $row['roles']))));
    }

    private function role(string $name, int $institutionId): ?Role
    {
        $key = $institutionId.'|'.$name;

        if (! array_key_exists($key, $this->roleCache)) {
            $this->roleCache[$key] = Role::query()->availableTo($institutionId)->where('name', $name)->where('is_active', true)->first();
        }

        return $this->roleCache[$key];
    }
}
