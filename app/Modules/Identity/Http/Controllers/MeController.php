<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Actions\Auth\ChangePassword;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Http\Resources\SecurityEventResource;
use App\Modules\Identity\Http\Resources\SessionResource;
use App\Modules\Identity\Http\Resources\TrustedDeviceResource;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Modules\Institution\Http\Resources\InstitutionSummaryResource;
use App\Shared\Audit\SecurityEvent;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Files\StoredFile;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * @tags My account
 */
class MeController extends Controller
{
    public function __construct(
        private CurrentInstitution $currentInstitution,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * Current user, institution, roles and permissions.
     */
    public function show(Request $request): MeResource
    {
        return new MeResource($this->user($request));
    }

    /**
     * Update my profile.
     */
    public function update(Request $request): MeResource
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'locale' => ['sometimes', 'string', Rule::in(['en', 'kn', 'hi', 'ta', 'te', 'ml', 'mr'])],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            /** Upload with purpose "avatar" first, then pass its id. */
            'avatar_file_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        if (array_key_exists('avatar_file_id', $validated)) {
            $validated['avatar_file_id'] = $this->resolveAvatar($user, $validated['avatar_file_id']);
        }

        $user->fill($validated)->save();

        return new MeResource($user->refresh());
    }

    /**
     * Change my password. Other devices are signed out.
     */
    public function changePassword(Request $request, ChangePassword $changePassword, PasswordPolicy $policy): JsonResponse
    {
        $user = $this->user($request);
        $institutionId = $this->currentInstitution->id();

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', $policy->rule($institutionId)],
        ]);

        $changePassword->handle($user, $validated['password'], $institutionId);

        return response()->json(['message' => 'Your password has been changed.']);
    }

    /**
     * Institutions I belong to (switch with the X-Institution header).
     */
    public function institutions(Request $request): AnonymousResourceCollection
    {
        return InstitutionSummaryResource::collection(
            $this->user($request)->institutions()->wherePivot('status', 'active')->orderBy('name')->get()
        );
    }

    /**
     * Set my default institution.
     */
    public function setDefaultInstitution(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate(['institution_id' => ['required', 'string']]);

        $institution = $user->institutions()->wherePivot('status', 'active')->where('institutions.ulid', $validated['institution_id'])->first();

        if ($institution === null) {
            throw new BusinessRuleException('You are not a member of this institution.', 'NOT_A_MEMBER', 403);
        }

        $user->institutions()->newPivotStatement()->where('user_id', $user->id)->update(['is_default' => false]);
        $user->institutions()->updateExistingPivot($institution->id, ['is_default' => true]);

        return response()->json(['message' => 'Default institution updated.']);
    }

    /**
     * Devices where I am signed in.
     */
    public function sessions(Request $request): AnonymousResourceCollection
    {
        return SessionResource::collection(
            $this->user($request)->tokens()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('last_used_at')->get()
        );
    }

    /**
     * Sign out one device.
     */
    public function revokeSession(Request $request, string $session): JsonResponse
    {
        $user = $this->user($request);
        $token = $user->tokens()->whereKey($session)->first();

        if ($token === null) {
            throw new BusinessRuleException('Session not found.', 'NOT_FOUND', 404);
        }

        $token->delete();
        $this->securityLogger->log(SecurityEventType::SessionRevoked, $user, ['device' => $token->name]);

        return response()->json(['message' => 'The device has been signed out.']);
    }

    /**
     * Sign out every other device.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();

        $count = $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        $this->securityLogger->log(SecurityEventType::SessionRevoked, $user, ['others' => true, 'count' => $count]);

        return response()->json(['message' => "Signed out of {$count} other device(s)."]);
    }

    /**
     * Devices that skip two-step verification.
     */
    public function trustedDevices(Request $request): AnonymousResourceCollection
    {
        return TrustedDeviceResource::collection(
            $this->user($request)->trustedDevices()->where('expires_at', '>', now())->latest('last_used_at')->get()
        );
    }

    /**
     * Forget a trusted device.
     */
    public function revokeTrustedDevice(Request $request, string $device): JsonResponse
    {
        $user = $this->user($request);
        $trusted = TrustedDevice::query()->where('user_id', $user->id)->where('ulid', $device)->first();

        if ($trusted === null) {
            throw new BusinessRuleException('Device not found.', 'NOT_FOUND', 404);
        }

        $trusted->delete();
        $this->securityLogger->log(SecurityEventType::TrustedDeviceRevoked, $user, ['device' => $trusted->name]);

        return response()->json(['message' => 'The device will ask for two-step verification again.']);
    }

    /**
     * My recent sign-ins and security events.
     */
    public function securityEvents(Request $request): AnonymousResourceCollection
    {
        $events = SecurityEvent::query()
            ->where('user_id', $this->user($request)->id)
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), 100));

        return SecurityEventResource::collection($events);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function resolveAvatar(User $user, ?string $fileUlid): ?int
    {
        if ($fileUlid === null) {
            return null;
        }

        $file = StoredFile::query()->where('ulid', $fileUlid)->where('uploaded_by', $user->id)->where('purpose', 'avatar')->first();

        if ($file === null) {
            throw new BusinessRuleException('Upload the photo with purpose "avatar" first.', 'AVATAR_FILE_INVALID', 422);
        }

        $file->attachable()->associate($user)->save();

        return $file->id;
    }
}
