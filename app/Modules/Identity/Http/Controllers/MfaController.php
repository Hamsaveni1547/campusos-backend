<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Actions\Mfa\ManageMfaMethods;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Http\Resources\MfaMethodResource;
use App\Modules\Identity\Models\MfaMethod;
use App\Modules\Identity\Services\MfaRequirement;
use App\Modules\Identity\Services\RecoveryCodeService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * @tags Two-step verification
 */
class MfaController extends Controller
{
    public function __construct(
        private ManageMfaMethods $mfa,
        private CurrentInstitution $currentInstitution,
    ) {}

    /**
     * My two-step verification status and methods.
     */
    public function index(Request $request, MfaRequirement $requirement, RecoveryCodeService $recoveryCodes): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            'enabled' => $user->hasMfaEnabled(),
            'required' => $requirement->isRequired($user, $this->currentInstitution->id()),
            'recovery_codes_remaining' => $recoveryCodes->remaining($user),
            'methods' => MfaMethodResource::collection($user->mfaMethods()->with('user')->orderByDesc('is_primary')->get())->resolve($request),
        ]]);
    }

    /**
     * Start authenticator app setup. Scan the QR code, then confirm with a code.
     */
    public function startTotp(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        return response()->json(['data' => $this->mfa->startTotp($this->user($request))]);
    }

    /**
     * Confirm authenticator app setup. Recovery codes are returned once on first setup.
     */
    public function confirmTotp(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $result = $this->mfa->confirmTotp($this->user($request), $validated['code'], $this->currentInstitution->id());

        return $this->confirmed($request, $result);
    }

    /**
     * Start email or SMS code setup. A code is sent to the destination.
     */
    public function startCodeMethod(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        $validated = $request->validate([
            'type' => ['required', Rule::in([MfaMethodType::Email->value, MfaMethodType::Sms->value])],
            'mobile' => ['nullable', 'required_if:type,sms', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        $sentTo = $this->mfa->startCodeMethod($this->user($request), MfaMethodType::from($validated['type']), $validated['mobile'] ?? null, $this->currentInstitution->id());

        return response()->json(['data' => ['sent_to' => $sentTo]]);
    }

    /**
     * Confirm email or SMS code setup.
     */
    public function confirmCodeMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([MfaMethodType::Email->value, MfaMethodType::Sms->value])],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $result = $this->mfa->confirmCodeMethod($this->user($request), MfaMethodType::from($validated['type']), $validated['code'], $this->currentInstitution->id());

        return $this->confirmed($request, $result);
    }

    /**
     * Make a method the default one offered at sign-in.
     */
    public function makePrimary(Request $request, string $method): JsonResponse
    {
        $user = $this->user($request);
        $this->mfa->makePrimary($user, $this->findMethod($user, $method));

        return response()->json(['message' => 'Primary method updated.']);
    }

    /**
     * Remove a method. Requires the current password.
     */
    public function destroy(Request $request, string $method): JsonResponse
    {
        $this->confirmPassword($request);
        $user = $this->user($request);

        $this->mfa->remove($user, $this->findMethod($user, $method), $this->currentInstitution->id());

        return response()->json(['message' => 'The method has been removed.']);
    }

    /**
     * Generate new recovery codes (old ones stop working). Shown once.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        return response()->json(['data' => [
            'recovery_codes' => $this->mfa->regenerateRecoveryCodes($this->user($request), $this->currentInstitution->id()),
        ]]);
    }

    /**
     * @param  array{method: MfaMethod, recovery_codes: array<int, string>|null}  $result
     */
    private function confirmed(Request $request, array $result): JsonResponse
    {
        $result['method']->load('user');

        return response()->json(['data' => [
            'method' => (new MfaMethodResource($result['method']))->resolve($request),
            'recovery_codes' => $result['recovery_codes'],
        ]]);
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password:sanctum']]);
    }

    private function findMethod(User $user, string $ulid): MfaMethod
    {
        $method = $user->mfaMethods()->where('ulid', $ulid)->first();

        if ($method === null) {
            throw new BusinessRuleException('Method not found.', 'NOT_FOUND', 404);
        }

        return $method;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
