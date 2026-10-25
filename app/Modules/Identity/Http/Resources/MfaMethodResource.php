<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Actions\Auth\SendMfaChallengeCode;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Models\MfaMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MfaMethod
 */
class MfaMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $destination = match ($this->type) {
            MfaMethodType::Email => $this->user->email,
            MfaMethodType::Totp => null,
        };

        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'is_primary' => $this->is_primary,
            'is_confirmed' => $this->isConfirmed(),
            'destination' => $destination !== null ? SendMfaChallengeCode::mask($destination) : null,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
