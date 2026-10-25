<?php

namespace App\Modules\Institution\Http\Resources;

use App\Modules\Institution\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Institution
 */
class InstitutionSummaryResource extends JsonResource
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
            'timezone' => $this->timezone,
            'logo_file_id' => $this->logo?->ulid,
            'is_default' => $this->whenPivotLoaded('institution_user', fn (): bool => (bool) $this->resource->getRelationValue('pivot')?->getAttribute('is_default')),
        ];
    }
}
