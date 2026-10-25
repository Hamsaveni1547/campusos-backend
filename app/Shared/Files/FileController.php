<?php

namespace App\Shared\Files;

use App\Models\User;
use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Files
 */
class FileController extends Controller
{
    public function __construct(
        private FileStorage $storage,
        private FileAccessPolicy $policy,
    ) {}

    /**
     * Upload a file. Then pass its id to the endpoint that uses it (avatar, document, import…).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'purpose' => ['required', 'string', Rule::in(array_keys((array) config('campusos.files.purposes')))],
        ]);

        $file = $this->storage->store($validated['file'], $validated['purpose'], $this->user($request));

        return (new FileResource($file))->response()->setStatusCode(201);
    }

    /**
     * File details.
     */
    public function show(Request $request, string $file): FileResource
    {
        return new FileResource($this->findViewable($request, $file));
    }

    /**
     * Short-lived download link (valid a few minutes, bound to you).
     */
    public function downloadUrl(Request $request, string $file): JsonResponse
    {
        $stored = $this->findViewable($request, $file);

        return response()->json(['data' => [
            'url' => $this->storage->temporaryDownloadUrl($stored, $this->user($request)),
            'expires_in_seconds' => (int) config('campusos.files.download_url_ttl_minutes') * 60,
        ]]);
    }

    /**
     * Stream a file using a signed link from the download-url endpoint.
     *
     * @unauthenticated
     */
    public function download(Request $request, string $file): StreamedResponse
    {
        $stored = StoredFile::query()->where('ulid', $file)->firstOrFail();
        $user = User::query()->where('ulid', (string) $request->query('user'))->firstOrFail();

        if (! $user->status->canLogIn() || ! $this->policy->canView($user, $stored)) {
            throw new AuthorizationException;
        }

        return $this->storage->stream($stored, $user);
    }

    /**
     * Delete an upload that has not been used yet.
     */
    public function destroy(Request $request, string $file): JsonResponse
    {
        $stored = $this->findViewable($request, $file);

        if (! $this->policy->canDelete($this->user($request), $stored)) {
            throw new BusinessRuleException('Only unused files you uploaded can be deleted.', 'FILE_NOT_DELETABLE', 403);
        }

        $this->storage->delete($stored);

        return response()->json(['message' => 'The file has been deleted.']);
    }

    private function findViewable(Request $request, string $ulid): StoredFile
    {
        $file = StoredFile::query()->where('ulid', $ulid)->firstOrFail();

        if (! $this->policy->canView($this->user($request), $file)) {
            // 404 rather than 403 so file ids cannot be probed.
            throw new BusinessRuleException('The requested resource was not found.', 'NOT_FOUND', 404);
        }

        return $file;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
