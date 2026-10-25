<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Services\PasswordPolicy;
use App\Modules\Institution\Models\Institution;
use Illuminate\Foundation\Http\FormRequest;

class RegisterApplicantRequest extends FormRequest
{
    private ?Institution $institution = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Institution code, e.g. "RVCE". */
            'institution' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:users,mobile'],
            'password' => ['required', 'string', 'confirmed', app(PasswordPolicy::class)->rule($this->institution()?->id)],
            /** Consent to the institution's privacy notice (DPDP Act). */
            'accept_terms' => ['accepted'],
        ];
    }

    public function institution(): ?Institution
    {
        return $this->institution ??= Institution::query()
            ->where('code', strtoupper((string) $this->input('institution')))
            ->first();
    }
}
