<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\MfaMethod;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app (TOTP, RFC 6238) secrets and verification with replay protection.
 */
class TotpService
{
    private const WINDOW = 1;

    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('campusos.mfa.issuer'), $user->email, $secret);
    }

    public function qrCodeSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * Verify a code and remember its time step so the same code cannot be used twice.
     */
    public function verify(MfaMethod $method, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($method->secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($method->secret, $code, $method->last_used_timestep, self::WINDOW);

        if ($timestep === false) {
            return false;
        }

        $method->forceFill([
            'last_used_timestep' => $timestep === true ? $this->google2fa->getTimestamp() : $timestep,
            'last_used_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Current code for a secret (used by tests only).
     */
    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
