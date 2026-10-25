<?php

namespace App\Shared\Files;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoredFile
 */
class FileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'purpose' => $this->purpose,
            'classification' => $this->classification->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'is_attached' => $this->isAttached(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
