<?php

namespace App\Shared\Files;

use App\Models\User;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\RequestContext;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores uploads on the private disk and hands out short-lived download links.
 */
class FileStorage
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private SettingsRepository $settings,
        private SecurityLogger $securityLogger,
        private RequestContext $context,
    ) {}

    /**
     * Validate and store an upload for a purpose defined in config/campusos.php.
     */
    public function store(UploadedFile $upload, string $purpose, ?User $uploader, ?Model $attachTo = null): StoredFile
    {
        $definition = $this->purpose($purpose);
        $mimeType = (string) $upload->getMimeType();

        if (! in_array($mimeType, $definition['mimes'], true)) {
            throw new BusinessRuleException(
                "Files of type [{$mimeType}] are not allowed for this upload.",
                'FILE_TYPE_NOT_ALLOWED',
                422,
                ['allowed' => $definition['mimes']],
            );
        }

        $maxKilobytes = (int) $this->settings->get('files', 'max_upload_kb');

        if ($upload->getSize() > $maxKilobytes * 1024) {
            throw new BusinessRuleException(
                "Files must be smaller than {$maxKilobytes} KB.",
                'FILE_TOO_LARGE',
                422,
                ['max_kb' => $maxKilobytes],
            );
        }

        $institutionId = $this->currentInstitution->id();
        $extension = strtolower($upload->getClientOriginalExtension() ?: (string) $upload->guessExtension());
        $ulid = (string) Str::ulid();
        $directory = sprintf('%s/%s/%s', $institutionId !== null ? 'institutions/'.$institutionId : 'platform', $purpose, now()->format('Y/m'));
        $disk = (string) config('campusos.files.disk');

        $path = $upload->storeAs($directory, $ulid.($extension !== '' ? '.'.$extension : ''), ['disk' => $disk]);

        if ($path === false) {
            throw new BusinessRuleException('The file could not be stored. Please try again.', 'FILE_STORE_FAILED', 500);
        }

        $file = new StoredFile([
            'institution_id' => $institutionId,
            'purpose' => $purpose,
            'classification' => $definition['classification'],
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($upload->getClientOriginalName(), 250, ''),
            'mime_type' => $mimeType,
            'extension' => $extension !== '' ? $extension : null,
            'size_bytes' => (int) $upload->getSize(),
            'checksum_sha256' => (string) hash_file('sha256', $upload->getRealPath()),
            'uploaded_by' => $uploader?->id,
        ]);
        $file->ulid = $ulid;

        if ($attachTo !== null) {
            $file->attachable()->associate($attachTo);
        }

        $file->save();

        return $file;
    }

    public function attach(StoredFile $file, Model $owner): StoredFile
    {
        $file->attachable()->associate($owner);
        $file->save();

        return $file;
    }

    /**
     * Signed URL that streams the file. The signature binds the file and user.
     */
    public function temporaryDownloadUrl(StoredFile $file, User $user): string
    {
        return URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->addMinutes((int) config('campusos.files.download_url_ttl_minutes')),
            ['file' => $file->ulid, 'user' => $user->ulid],
        );
    }

    public function stream(StoredFile $file, ?User $user): StreamedResponse
    {
        $this->logAccess($file, $user, 'download');

        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function contents(StoredFile $file): string
    {
        return (string) Storage::disk($file->disk)->get($file->path);
    }

    public function absolutePath(StoredFile $file): string
    {
        return Storage::disk($file->disk)->path($file->path);
    }

    public function delete(StoredFile $file): void
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }

    /**
     * @return array{classification: FileClassification, mimes: array<int, string>}
     */
    public function purpose(string $purpose): array
    {
        $definition = config("campusos.files.purposes.{$purpose}");

        if (! is_array($definition)) {
            throw new BusinessRuleException("Unknown upload purpose [{$purpose}].", 'FILE_PURPOSE_UNKNOWN', 422);
        }

        return [
            'classification' => FileClassification::from($definition['classification']),
            'mimes' => $definition['mimes'],
        ];
    }

    private function logAccess(StoredFile $file, ?User $user, string $action): void
    {
        if (! $file->classification->logsDownloads()) {
            return;
        }

        FileAccessLog::query()->create([
            'file_id' => $file->id,
            'user_id' => $user?->id,
            'action' => $action,
            'ip_address' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'created_at' => now(),
        ]);

        $this->securityLogger->log(
            SecurityEventType::ConfidentialFileDownloaded,
            $user,
            ['file' => $file->ulid, 'purpose' => $file->purpose],
            institutionId: $file->institution_id,
        );
    }
}
