<?php

namespace App\Modules\Institution\Http\Requests;

use App\Modules\Institution\Enums\InstitutionType;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules for institution profile fields.
 */
final class InstitutionAttributes
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $creating, ?int $ignoreId = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('institutions', 'code')->ignore($ignoreId)],
            'name' => [$required, 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => [$required, Rule::enum(InstitutionType::class)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'string', 'size:2'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'timezone' => ['sometimes', 'timezone:all'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'affiliation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'accreditation_codes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'established_year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:'.now()->year],
        ];
    }
}
