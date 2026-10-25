<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Email, username or mobile number. */
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            /** Shown in the user's list of signed-in devices, e.g. "Chrome on Windows". */
            'device_name' => ['required', 'string', 'max:255'],
            /** Token returned earlier by "remember this device"; skips the second factor while valid. */
            'trusted_device_token' => ['nullable', 'string', 'max:128'],
        ];
    }
}
