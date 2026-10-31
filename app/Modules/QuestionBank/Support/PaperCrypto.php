<?php

namespace App\Modules\QuestionBank\Support;

use App\Shared\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Encryption for question bank content, using Laravel's encrypter with AES-256-GCM (authenticated).
 *
 * Question bodies are encrypted with the master key. A sealed paper gets its own random data key:
 * the payload is encrypted with it, the data key is wrapped (encrypted) with the master key, and a
 * SHA-256 digest of the plain payload detects tampering on opening.
 */
final class PaperCrypto
{
    private const CIPHER = 'aes-256-gcm';

    public function encrypt(string $plain): string
    {
        return $this->master()->encryptString($plain);
    }

    public function decrypt(string $cipher): string
    {
        try {
            return $this->master()->decryptString($cipher);
        } catch (DecryptException) {
            throw new BusinessRuleException('Stored content could not be decrypted.', 'CONTENT_TAMPERED', 500);
        }
    }

    /**
     * @return array{payload: string, wrapped_key: string, hash: string}
     */
    public function seal(string $plain): array
    {
        $dataKey = Encrypter::generateKey(self::CIPHER);

        return [
            'payload' => (new Encrypter($dataKey, self::CIPHER))->encryptString($plain),
            'wrapped_key' => $this->master()->encryptString(base64_encode($dataKey)),
            'hash' => hash('sha256', $plain),
        ];
    }

    public function open(string $payload, string $wrappedKey, string $hash): string
    {
        try {
            $dataKey = base64_decode($this->master()->decryptString($wrappedKey), true);
            $plain = (new Encrypter((string) $dataKey, self::CIPHER))->decryptString($payload);
        } catch (DecryptException) {
            throw new BusinessRuleException('The sealed paper could not be opened.', 'CONTENT_TAMPERED', 500);
        }

        if (! hash_equals($hash, hash('sha256', $plain))) {
            throw new BusinessRuleException('The sealed paper has been altered.', 'CONTENT_TAMPERED', 500);
        }

        return $plain;
    }

    /**
     * Dedicated key from CAMPUSOS_QBANK_KEY (base64), otherwise one derived from the app key.
     */
    private function master(): Encrypter
    {
        $configured = (string) config('campusos.qbank_key');
        $key = $configured !== ''
            ? (string) base64_decode(str_starts_with($configured, 'base64:') ? substr($configured, 7) : $configured, true)
            : hash_hmac('sha256', 'campusos-question-bank', (string) config('app.key'), true);

        return new Encrypter($key, self::CIPHER);
    }
}
