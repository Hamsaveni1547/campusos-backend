<?php

namespace App\Modules\Institution\Http\Resources;

use App\Modules\Institution\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Institution
 */
class InstitutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'parent' => $this->whenLoaded('parent', fn (): ?array => $this->parent === null ? null : [
                'id' => $this->parent->ulid,
                'code' => $this->parent->code,
                'name' => $this->parent->name,
            ]),
            'children_count' => $this->whenCounted('children'),
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => [
                'line' => $this->address_line,
                'city' => $this->city,
                'state' => $this->state,
                'country' => $this->country,
                'postal_code' => $this->postal_code,
            ],
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'affiliation' => $this->affiliation,
            'accreditation_codes' => $this->accreditation_codes,
            'established_year' => $this->established_year,
            'logo_file_id' => $this->logo?->ulid,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->when($request->user()?->is_platform_admin === true, $this->suspension_reason),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
