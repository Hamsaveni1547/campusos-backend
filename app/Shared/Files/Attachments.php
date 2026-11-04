<?php

namespace App\Shared\Files;

use App\Models\User;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Files a user uploaded (purpose "attachment") linked to a record, e.g. a complaint or an announcement.
 * Who may download them is decided by the record's policy ("view").
 */
class Attachments
{
    public const MAX = 5;

    public function __construct(private FileStorage $storage) {}

    /**
     * @param  list<string>  $fileIds  ULIDs of the user's own, not yet attached uploads
     */
    public function attach(array $fileIds, Model $owner, User $user): void
    {
        if ($fileIds === []) {
            return;
        }

        if (count($fileIds) + $this->query($owner)->count() > self::MAX) {
            throw new BusinessRuleException('You can attach up to '.self::MAX.' files.', 'TOO_MANY_FILES', 422);
        }

        $files = StoredFile::query()->whereIn('ulid', $fileIds)->where('uploaded_by', $user->id)->where('purpose', 'attachment')->whereNull('attachable_id')->get();

        if ($files->count() !== count(array_unique($fileIds))) {
            throw new BusinessRuleException('One of the files is not yours or is already used.', 'FILE_INVALID', 422);
        }

        foreach ($files as $file) {
            $this->storage->attach($file, $owner);
        }
    }

    /**
     * @return list<array{id: string, name: string, mime_type: string, size_bytes: int}>
     */
    public function present(Model $owner): array
    {
        return $this->query($owner)->orderBy('id')->get()
            ->map(fn (StoredFile $f): array => ['id' => $f->ulid, 'name' => $f->original_name, 'mime_type' => $f->mime_type, 'size_bytes' => $f->size_bytes])->values()->all();
    }

    /**
     * @return Builder<StoredFile>
     */
    private function query(Model $owner): Builder
    {
        return StoredFile::query()->where('attachable_type', $owner->getMorphClass())->where('attachable_id', $owner->getKey());
    }
}
