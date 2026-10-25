<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Authorization\IdentityPermissions;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(IdentityPermissions::UsersCreate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $institutionId = app(CurrentInstitution::class)->id();

        return [
            'name' => ['required', 'string', 'max:150'],
            /** If a person with this email already exists in another institution, they are linked instead of duplicated. */
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/', Rule::unique('users', 'mobile')->where(fn ($query) => $query->where('email', '!=', mb_strtolower((string) $this->input('email'))))],
            'username' => ['nullable', 'string', 'alpha_dash', 'min:3', 'max:64', Rule::unique('users', 'username')->where(fn ($query) => $query->where('email', '!=', mb_strtolower((string) $this->input('email'))))],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'timezone:all'],
            /** Role ids (ULIDs) to assign straight away. */
            'roles' => ['sometimes', 'array', 'max:10'],
            'roles.*' => ['string', 'size:26'],
            /** true (default): email an invitation to set a password. false: return a temporary password once. */
            'send_invite' => ['sometimes', 'boolean'],
            /** Optional initial password (user must change it at first sign-in). */
            'password' => ['nullable', 'string', app(PasswordPolicy::class)->rule($institutionId)],
        ];
    }
}
