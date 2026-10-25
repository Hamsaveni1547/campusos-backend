<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Actions\Auth\AttemptLogin;
use App\Modules\Identity\Actions\Auth\CompleteMfaChallenge;
use App\Modules\Identity\Actions\Auth\RegisterApplicant;
use App\Modules\Identity\Actions\Auth\ResetPassword;
use App\Modules\Identity\Actions\Auth\SendMfaChallengeCode;
use App\Modules\Identity\Actions\Auth\VerifyEmail;
use App\Modules\Identity\Enums\MfaMethodType;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterApplicantRequest;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Services\LoginResult;
use App\Modules\Institution\Models\Institution;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * @tags Authentication
 */
class AuthController extends Controller
{
    /**
     * Sign in.
     *
     * Returns an access token, or `mfa_required: true` with a `challenge_token`
     * when the account uses two-step verification.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, AttemptLogin $attemptLogin): JsonResponse
    {
        $result = $attemptLogin->handle(
            $request->string('login')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
            $request->input('trusted_device_token'),
        );

        return $this->loginResponse($result);
    }

    /**
     * Send a sign-in code by email or SMS for an open MFA challenge.
     *
     * @unauthenticated
     */
    public function sendMfaCode(Request $request, SendMfaChallengeCode $sendCode): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'max:128'],
            'method' => ['required', Rule::in([MfaMethodType::Email->value, MfaMethodType::Sms->value])],
        ]);

        $sent = $sendCode->handle($validated['challenge_token'], MfaMethodType::from($validated['method']));

        return response()->json(['data' => [
            'sent_to' => $sent['destination'],
            'expires_at' => $sent['expires_at']->toIso8601String(),
        ]]);
    }

    /**
     * Complete sign-in with the second factor.
     *
     * @unauthenticated
     */
    public function verifyMfa(Request $request, CompleteMfaChallenge $completeChallenge): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'max:128'],
            'method' => ['required', Rule::in(['totp', 'email', 'sms', 'recovery_code'])],
            'code' => ['required', 'string', 'max:32'],
            /** Skip the second factor on this device for the configured number of days. */
            'remember_device' => ['sometimes', 'boolean'],
        ]);

        $result = $completeChallenge->handle(
            $validated['challenge_token'],
            $validated['method'],
            $validated['code'],
            (bool) ($validated['remember_device'] ?? false),
        );

        return $this->loginResponse($result);
    }

    /**
     * Sign out of the current device.
     */
    public function logout(Request $request, SecurityLogger $securityLogger): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $securityLogger->log(SecurityEventType::Logout, $user);

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Register as an admission applicant.
     *
     * A 6-digit code is emailed; confirm it with `POST /auth/verify-email` after signing in.
     *
     * @unauthenticated
     */
    public function register(RegisterApplicantRequest $request, RegisterApplicant $registerApplicant): JsonResponse
    {
        $institution = $request->institution();

        if ($institution === null) {
            throw new BusinessRuleException('Institution not found.', 'INSTITUTION_NOT_FOUND', 404);
        }

        $user = $registerApplicant->handle($institution, $request->safe()->only(['name', 'email', 'mobile', 'password']));

        return response()->json([
            'message' => 'Registration successful. We sent a verification code to your email.',
            'data' => ['id' => $user->ulid, 'email' => $user->email, 'status' => $user->status->value],
        ], 201);
    }

    /**
     * Confirm the email address with the emailed code.
     */
    public function verifyEmail(Request $request, VerifyEmail $verifyEmail): MeResource
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:12']]);

        /** @var User $user */
        $user = $request->user();

        return new MeResource($verifyEmail->verify($user, $validated['code']));
    }

    /**
     * Send a new email verification code.
     */
    public function resendEmailVerification(Request $request, VerifyEmail $verifyEmail): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $verifyEmail->send($user);

        return response()->json(['message' => 'A new code was sent to your email.']);
    }

    /**
     * Request a password reset link.
     *
     * Always responds with success so the response does not reveal whether an account exists.
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request, ResetPassword $resetPassword): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $resetPassword->sendLink($validated['email']);

        return response()->json(['message' => 'If an account exists for this email, a reset link has been sent.']);
    }

    /**
     * Set a new password using the emailed reset token.
     *
     * @unauthenticated
     */
    public function resetPassword(Request $request, ResetPassword $resetPassword): JsonResponse
    {
        return $this->completeReset($request, $resetPassword, false);
    }

    /**
     * Set the first password using the invitation token (accounts created by an administrator).
     *
     * @unauthenticated
     */
    public function setupAccount(Request $request, ResetPassword $resetPassword): JsonResponse
    {
        return $this->completeReset($request, $resetPassword, true);
    }

    private function completeReset(Request $request, ResetPassword $resetPassword, bool $isSetup): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
        ]);

        $validated = $request->validate([
            'email' => ['required'],
            'token' => ['required'],
            'password' => ['required', 'string', 'confirmed', $resetPassword->passwordRuleFor($request->string('email')->toString())],
        ]);

        $resetPassword->reset($validated['email'], $validated['token'], $validated['password'], $isSetup);

        return response()->json(['message' => $isSetup ? 'Your account is ready. You can now sign in.' : 'Your password has been reset. You can now sign in.']);
    }

    private function loginResponse(LoginResult $result): JsonResponse
    {
        if ($result->requiresMfa()) {
            return response()->json(['data' => [
                'mfa_required' => true,
                'challenge_token' => $result->challengeToken,
                'challenge_expires_at' => $result->challengeExpiresAt?->toIso8601String(),
                'methods' => array_values(array_map(fn (MfaMethodType $type): string => $type->value, $result->methods)),
                'recovery_code_allowed' => true,
            ]]);
        }

        $token = $result->tokenModel;
        $currentInstitution = app(CurrentInstitution::class);
        $institution = $token?->institution_id !== null ? Institution::query()->find($token->institution_id) : null;

        $user = $result->user;
        $user->withAccessToken($token);

        $me = $currentInstitution->runAs($institution, fn () => (new MeResource($user))->resolve());

        return response()->json(['data' => [
            'mfa_required' => false,
            'token_type' => 'Bearer',
            'access_token' => $result->accessToken,
            'expires_at' => $token?->expires_at?->toIso8601String(),
            'trusted_device_token' => $result->trustedDeviceToken,
            'user' => $me,
        ]]);
    }
}
